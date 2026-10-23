<?php

namespace App\Http\Controllers;

use App\Enums\AuditAction;
use App\Models\Appointment;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\ClinicTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AgendaController extends Controller
{
    /**
     * Agenda: o calendário do mês (ou a semana) e, ao lado, os agendamentos
     * do dia escolhido. Pode mostrar só um profissional.
     */
    public function index(Request $request, AuditLogger $audit): View
    {
        Gate::authorize('viewAny', Appointment::class);

        $filters = $request->validate([
            'data' => ['nullable', 'date_format:Y-m-d', 'after:2000-01-01', 'before:2100-01-01'],
            'visao' => ['nullable', Rule::in(['mes', 'semana'])],
            'profissional' => ['nullable', 'integer'],
        ]);

        $date = CarbonImmutable::createFromFormat('!Y-m-d', $filters['data'] ?? ClinicTime::today(), ClinicTime::timezone());
        $view = $filters['visao'] ?? 'mes';
        $professionalId = isset($filters['profissional']) ? (int) $filters['profissional'] : null;

        // Semanas inteiras, de segunda a domingo, em dias do calendário da clínica:
        // o mês em várias linhas, ou só a semana do dia escolhido.
        [$first, $last] = $view === 'semana'
            ? [$date->startOfWeek(CarbonImmutable::MONDAY), $date->endOfWeek(CarbonImmutable::SUNDAY)]
            : [$date->startOfMonth()->startOfWeek(CarbonImmutable::MONDAY), $date->endOfMonth()->endOfWeek(CarbonImmutable::SUNDAY)];

        // Uma consulta para a grade toda; a separação por dia é feita em memória.
        $appointmentsByDay = $this->appointmentsBetween($first, $last, $professionalId)
            ->groupBy(fn (Appointment $appointment) => ClinicTime::format($appointment->scheduled_at, 'Y-m-d'));

        // Só a lista do dia tem ações, então só ela carrega o atendimento ligado.
        $dayAppointments = (new Collection($appointmentsByDay->get($date->format('Y-m-d'), [])))->load('treatment');

        // A agenda mostra quem vem e com quem: é leitura de dado de paciente.
        $audit->log(AuditAction::AgendaViewed, null, ['view' => $view, 'from' => $first->format('Y-m-d'), 'to' => $last->format('Y-m-d'), 'professional_id' => $professionalId]);

        return view('agenda.index', [
            'date' => $date,
            'today' => ClinicTime::today(),
            'view' => $view,
            'professionalId' => $professionalId,
            'professionals' => User::query()->attending()->orderBy('name')->pluck('name', 'id'),
            'days' => $this->days($first, $last),
            'appointmentsByDay' => $appointmentsByDay,
            'dayAppointments' => $dayAppointments,
            'previous' => $view === 'semana' ? $date->subWeek()->format('Y-m-d') : $this->shiftMonth($date, -1),
            'next' => $view === 'semana' ? $date->addWeek()->format('Y-m-d') : $this->shiftMonth($date, 1),
        ]);
    }

    /**
     * @return Collection<int, Appointment>
     */
    private function appointmentsBetween(CarbonImmutable $first, CarbonImmutable $last, ?int $professionalId): Collection
    {
        return Appointment::query()
            ->with(['patient:id,name', 'professional:id,name', 'procedure:id,name'])
            // Agendamentos de pacientes arquivados não aparecem.
            ->whereHas('patient')
            ->when($professionalId, fn ($query, int $id) => $query->where('professional_id', $id))
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
