<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Support\ClinicTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class AgendaController extends Controller
{
    /**
     * Agenda: o calendário do mês e, ao lado, os agendamentos do dia escolhido.
     */
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Appointment::class);

        $filters = $request->validate([
            'data' => ['nullable', 'date_format:Y-m-d', 'after:2000-01-01', 'before:2100-01-01'],
        ]);

        $date = CarbonImmutable::createFromFormat('!Y-m-d', $filters['data'] ?? ClinicTime::today(), ClinicTime::timezone());

        // O mês é exibido em semanas inteiras, de segunda a domingo, em dias
        // do calendário da clínica.
        $first = $date->startOfMonth()->startOfWeek(CarbonImmutable::MONDAY);
        $last = $date->endOfMonth()->endOfWeek(CarbonImmutable::SUNDAY);

        // Uma consulta para a grade toda; a separação por dia é feita em memória.
        $appointmentsByDay = $this->appointmentsBetween($first, $last)
            ->groupBy(fn (Appointment $appointment) => ClinicTime::format($appointment->scheduled_at, 'Y-m-d'));

        // Só a lista do dia tem ações, então só ela carrega o atendimento ligado.
        $dayAppointments = (new Collection($appointmentsByDay->get($date->format('Y-m-d'), [])))->load('treatment');

        return view('agenda.index', [
            'date' => $date,
            'today' => ClinicTime::today(),
            'days' => $this->days($first, $last),
            'appointmentsByDay' => $appointmentsByDay,
            'dayAppointments' => $dayAppointments,
            'previous' => $this->shiftMonth($date, -1),
            'next' => $this->shiftMonth($date, 1),
        ]);
    }

    /**
     * @return Collection<int, Appointment>
     */
    private function appointmentsBetween(CarbonImmutable $first, CarbonImmutable $last): Collection
    {
        return Appointment::query()
            ->with(['patient:id,name', 'professional:id,name', 'procedure:id,name'])
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

    /**
     * Primeiro dia do mês anterior ou do seguinte.
     */
    private function shiftMonth(CarbonImmutable $date, int $direction): string
    {
        return $date->startOfMonth()->addMonthsNoOverflow($direction)->format('Y-m-d');
    }
}
