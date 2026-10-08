<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Support\ClinicTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AgendaController extends Controller
{
    private const VIEWS = ['dia' => 'Dia', 'semana' => 'Semana', 'mes' => 'Mês'];

    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Appointment::class);

        $filters = $request->validate([
            'data' => ['nullable', 'date_format:Y-m-d', 'after:2000-01-01', 'before:2100-01-01'],
            'visao' => ['nullable', Rule::in(array_keys(self::VIEWS))],
        ]);

        $view = $filters['visao'] ?? 'dia';
        $date = CarbonImmutable::createFromFormat('!Y-m-d', $filters['data'] ?? ClinicTime::today(), ClinicTime::timezone());

        // Intervalo exibido, em dias do calendário da clínica. A semana vai
        // de segunda a domingo; o mês mostra semanas inteiras.
        [$first, $last] = match ($view) {
            'semana' => [$date->startOfWeek(CarbonImmutable::MONDAY), $date->endOfWeek(CarbonImmutable::SUNDAY)],
            'mes' => [$date->startOfMonth()->startOfWeek(CarbonImmutable::MONDAY), $date->endOfMonth()->endOfWeek(CarbonImmutable::SUNDAY)],
            default => [$date, $date],
        };

        // Uma consulta para o intervalo todo; a separação por dia é feita em memória.
        $appointments = $this->appointmentsBetween($first, $last, detailed: $view === 'dia');

        return view('agenda.index', [
            'view' => $view,
            'views' => self::VIEWS,
            'date' => $date,
            'today' => ClinicTime::today(),
            'days' => $this->days($first, $last),
            'appointmentsByDay' => $appointments->groupBy(fn (Appointment $appointment) => ClinicTime::format($appointment->scheduled_at, 'Y-m-d')),
            'previous' => $this->shift($date, $view, -1),
            'next' => $this->shift($date, $view, 1),
        ]);
    }

    /**
     * @return Collection<int, Appointment>
     */
    private function appointmentsBetween(CarbonImmutable $first, CarbonImmutable $last, bool $detailed): Collection
    {
        return Appointment::query()
            // A visão do dia mostra ações e precisa do atendimento ligado; as
            // visões resumidas carregam só o que exibem.
            ->with($detailed
                ? ['patient', 'professional:id,name', 'procedure:id,name', 'treatment']
                : ['patient:id,name', 'procedure:id,name'])
            // Agendamentos de pacientes arquivados não aparecem.
            ->whereHas('patient')
            ->whereBetween('scheduled_at', [$first->startOfDay()->utc(), $last->endOfDay()->utc()])
            ->orderBy('scheduled_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return list<CarbonImmutable>
     */
    private function days(CarbonImmutable $first, CarbonImmutable $last): array
    {
        $days = [];

        for ($day = $first->startOfDay(); $day->lessThanOrEqualTo($last); $day = $day->addDay()) {
            $days[] = $day;
        }

        return $days;
    }

    private function shift(CarbonImmutable $date, string $view, int $direction): string
    {
        $shifted = match ($view) {
            'semana' => $date->addWeeks($direction),
            'mes' => $date->startOfMonth()->addMonthsNoOverflow($direction),
            default => $date->addDays($direction),
        };

        return $shifted->format('Y-m-d');
    }
}
