<?php

namespace App\Services;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\User;
use App\Support\ClinicTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AppointmentScheduler
{
    /**
     * Grava o agendamento garantindo que o profissional não fique com dois
     * horários sobrepostos, mesmo com duas pessoas agendando ao mesmo tempo.
     *
     * @throws ValidationException
     */
    public function save(Appointment $appointment): void
    {
        DB::transaction(function () use ($appointment): void {
            // Trava a linha do profissional: serializa os agendamentos dele e
            // fecha a janela entre a checagem de conflito e a gravação.
            User::query()->whereKey($appointment->professional_id)->lockForUpdate()->first();

            $conflict = Appointment::query()
                ->where('professional_id', $appointment->professional_id)
                // Confirmados também ocupam o horário.
                ->whereIn('status', AppointmentStatus::open())
                // Sobreposição real; horários que apenas se encostam são aceitos.
                ->where('scheduled_at', '<', $appointment->ends_at)
                ->where('ends_at', '>', $appointment->scheduled_at)
                ->when($appointment->exists, fn ($query) => $query->whereKeyNot($appointment->getKey()))
                ->with('procedure:id,name')
                ->orderBy('scheduled_at')
                ->first();

            if ($conflict !== null && $appointment->isOpen()) {
                throw ValidationException::withMessages(['scheduled_at' => $this->conflictMessage($appointment, $conflict)]);
            }

            $appointment->save();
        });
    }

    /**
     * Diz qual horário está ocupado e o próximo livre no mesmo dia com a
     * mesma duração, para a recepção não ter de tentar às cegas. Sem o nome
     * do paciente: a mensagem de erro não leva dado pessoal.
     */
    private function conflictMessage(Appointment $appointment, Appointment $conflict): string
    {
        $message = sprintf(
            'Este profissional já tem um agendamento das %s às %s%s.',
            ClinicTime::format($conflict->scheduled_at, 'H:i'),
            ClinicTime::format($conflict->ends_at, 'H:i'),
            $conflict->procedure ? ' ('.$conflict->procedure->name.')' : '',
        );

        $free = $this->nextFreeSlot($appointment);

        return $free === null
            ? $message.' Não há outro horário livre com essa duração neste dia.'
            : $message.' Próximo horário livre com essa duração: '.ClinicTime::format($free, 'H:i').'.';
    }

    /**
     * Primeiro início, a partir do horário pedido, em que a duração cabe entre
     * os agendamentos em aberto do profissional no mesmo dia da clínica.
     */
    private function nextFreeSlot(Appointment $appointment): ?CarbonImmutable
    {
        [, $dayEnd] = ClinicTime::dayBounds(ClinicTime::format($appointment->scheduled_at, 'Y-m-d'));
        $duration = $appointment->duration_minutes;

        $busy = Appointment::query()
            ->where('professional_id', $appointment->professional_id)
            ->whereIn('status', AppointmentStatus::open())
            ->where('ends_at', '>', $appointment->scheduled_at)
            ->where('scheduled_at', '<=', $dayEnd)
            ->when($appointment->exists, fn ($query) => $query->whereKeyNot($appointment->getKey()))
            ->orderBy('scheduled_at')
            ->get(['scheduled_at', 'ends_at']);

        $start = CarbonImmutable::instance($appointment->scheduled_at);

        foreach ($busy as $other) {
            if ($start->addMinutes($duration)->lessThanOrEqualTo($other->scheduled_at)) {
                break;
            }

            $start = $start->max(CarbonImmutable::instance($other->ends_at));
        }

        return $start->addMinutes($duration)->lessThanOrEqualTo($dayEnd->addSecond()) ? $start : null;
    }
}
