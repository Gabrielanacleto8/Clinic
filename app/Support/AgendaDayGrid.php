<?php

namespace App\Support;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Collection;

/**
 * Visão do dia da agenda: uma coluna por profissional e uma linha a cada 15
 * minutos. Calcula em que linha cada agendamento começa e quantas ocupa; a
 * view só desenha. Cancelados ficam de fora da grade (estão na lista do dia),
 * porque o horário deles pode ter sido ocupado por outro agendamento.
 */
final class AgendaDayGrid
{
    public const SLOT_MINUTES = 15;

    /** Primeiro e último minuto do dia mostrados (múltiplos de hora cheia). */
    public readonly int $fromMinute;

    public readonly int $untilMinute;

    /**
     * @param  Collection<int, Appointment>  $appointments  Do dia, com professional e patient carregados.
     * @param  Collection<int|string, string>  $professionals  Id => nome dos profissionais que atendem.
     */
    public function __construct(
        private readonly CarbonImmutable $day,
        private readonly Collection $appointments,
        private readonly Collection $professionals,
        private readonly ?int $onlyProfessionalId,
    ) {
        $visible = $this->visibleAppointments();
        $starts = $visible->map(fn (Appointment $appointment) => $this->minuteOfDay($appointment->scheduled_at));
        $ends = $visible->map(fn (Appointment $appointment) => $this->minuteOfDay($appointment->ends_at, endOfDay: true));

        $from = (int) config('clinic.agenda.day_view_from') * 60;
        $until = (int) config('clinic.agenda.day_view_until') * 60;

        $this->fromMinute = intdiv(min($from, $starts->min() ?? $from), 60) * 60;
        $this->untilMinute = (int) ceil(max($until, $ends->max() ?? $until) / 60) * 60;
    }

    public function slots(): int
    {
        return intdiv($this->untilMinute - $this->fromMinute, self::SLOT_MINUTES);
    }

    /**
     * Horas cheias da faixa, com a linha (1-based) em que cada uma começa.
     *
     * @return list<array{label: string, row: int}>
     */
    public function hours(): array
    {
        $hours = [];

        for ($minute = $this->fromMinute; $minute < $this->untilMinute; $minute += 60) {
            $hours[] = ['label' => sprintf('%02d:00', intdiv($minute, 60)), 'row' => $this->row($minute)];
        }

        return $hours;
    }

    /**
     * Meias horas da faixa, para os atalhos de novo agendamento.
     *
     * @return list<array{time: string, row: int}>
     */
    public function halfHours(): array
    {
        $slots = [];

        for ($minute = $this->fromMinute; $minute < $this->untilMinute; $minute += 30) {
            $slots[] = ['time' => sprintf('%02d:%02d', intdiv($minute, 60), $minute % 60), 'row' => $this->row($minute)];
        }

        return $slots;
    }

    /**
     * Uma coluna por profissional: os que atendem e quem tem agendamento no
     * dia mesmo sem atender mais (desativado). Com filtro, só o escolhido.
     *
     * @return list<array{id: int, name: string, items: list<array{appointment: Appointment, row: int, span: int}>}>
     */
    public function columns(): array
    {
        $byProfessional = $this->visibleAppointments()->groupBy('professional_id');

        $names = $this->professionals->all();

        foreach ($this->appointments as $appointment) {
            $names[$appointment->professional_id] ??= $appointment->professional->name;
        }

        if ($this->onlyProfessionalId !== null) {
            $names = array_intersect_key($names, [$this->onlyProfessionalId => true]);
        }

        asort($names);

        $columns = [];

        foreach ($names as $id => $name) {
            $columns[] = [
                'id' => (int) $id,
                'name' => $name,
                'items' => $byProfessional->get($id, collect())->map(function (Appointment $appointment): array {
                    $start = $this->minuteOfDay($appointment->scheduled_at);
                    $end = max($start + self::SLOT_MINUTES, $this->minuteOfDay($appointment->ends_at, endOfDay: true));

                    return [
                        'appointment' => $appointment,
                        'row' => $this->row($start),
                        // Arredonda para cima: 20 minutos ocupam duas linhas de 15.
                        'span' => (int) ceil(($end - $start) / self::SLOT_MINUTES),
                    ];
                })->values()->all(),
            ];
        }

        return $columns;
    }

    /**
     * @return Collection<int, Appointment>
     */
    private function visibleAppointments(): Collection
    {
        return $this->appointments->reject(fn (Appointment $appointment) => $appointment->status === AppointmentStatus::Cancelled)->values();
    }

    /**
     * Linha da grade (começando em 1) de um minuto do dia.
     */
    private function row(int $minute): int
    {
        return intdiv($minute - $this->fromMinute, self::SLOT_MINUTES) + 1;
    }

    /**
     * Minuto do dia no fuso da clínica. Um fim que cai no dia seguinte vale como 24h.
     */
    private function minuteOfDay(DateTimeInterface $moment, bool $endOfDay = false): int
    {
        $local = ClinicTime::local($moment);

        if ($endOfDay && $local->format('Y-m-d') > $this->day->format('Y-m-d')) {
            return 24 * 60;
        }

        return (int) $local->format('G') * 60 + (int) $local->format('i');
    }
}
