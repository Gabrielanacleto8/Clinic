<?php

namespace App\Services;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AppointmentScheduler
{
    public const CONFLICT_MESSAGE = 'Este profissional já tem um agendamento nesse horário.';

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
                ->exists();

            if ($conflict && $appointment->isOpen()) {
                throw ValidationException::withMessages(['scheduled_at' => self::CONFLICT_MESSAGE]);
            }

            $appointment->save();
        });
    }
}
