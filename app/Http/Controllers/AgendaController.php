<?php

namespace App\Http\Controllers;

use App\Enums\AppointmentStatus;
use App\Enums\AuditAction;
use App\Models\Appointment;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\ClinicTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
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
            // Um profissional, ou "todos". Quem atende abre a própria agenda por padrão.
            'profissional' => ['nullable', Rule::anyOf([['integer'], [Rule::in(['todos'])]])],
        ]);

        $date = CarbonImmutable::createFromFormat('!Y-m-d', $filters['data'] ?? ClinicTime::today(), ClinicTime::timezone());
        $view = $filters['visao'] ?? 'mes';
        $professionalId = $this->professionalFilter($request, $filters['profissional'] ?? null);

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
            // O que a navegação e as ações da lista do dia devem manter. O profissional
            // só vai explícito quando foi escolhido: sem ele, quem atende volta à própria agenda.
            'agendaQuery' => array_filter(['visao' => $view === 'semana' ? 'semana' : null, 'profissional' => $filters['profissional'] ?? null]),
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
            ->with(['patient:id,name,phone', 'professional:id,name,is_active', 'procedure:id,name'])
            // Agendamentos de pacientes arquivados não aparecem.
            ->whereHas('patient')
            ->when($professionalId, fn ($query, int $id) => $query->where('professional_id', $id))
            ->whereBetween('scheduled_at', [$first->startOfDay()->utc(), $last->endOfDay()->utc()])
            ->orderBy('scheduled_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * Horários ocupados de um profissional em um dia, para o formulário de
     * agendamento mostrar onde cabe um encaixe. Só horários e procedimentos,
     * sem nome de paciente: é o que a mensagem de conflito também diz.
     */
    public function occupancy(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Appointment::class);

        $filters = $request->validate([
            'data' => ['required', 'date_format:Y-m-d'],
            'profissional' => ['required', 'integer'],
            'exceto' => ['nullable', 'integer'],
        ]);

        [$start, $end] = ClinicTime::dayBounds($filters['data']);

        $slots = Appointment::query()
            ->with('procedure:id,name')
            ->where('professional_id', $filters['profissional'])
            ->whereIn('status', AppointmentStatus::open())
            ->whereHas('patient')
            ->whereBetween('scheduled_at', [$start, $end])
            ->when($filters['exceto'] ?? null, fn ($query, int|string $id) => $query->whereKeyNot($id))
            ->orderBy('scheduled_at')
            ->get(['id', 'procedure_id', 'scheduled_at', 'ends_at'])
            ->map(fn (Appointment $appointment) => [
                'from' => ClinicTime::format($appointment->scheduled_at, 'H:i'),
                'to' => ClinicTime::format($appointment->ends_at, 'H:i'),
                'procedure' => $appointment->procedure?->name,
            ]);

        return response()->json(['slots' => $slots]);
    }

    /**
     * Sem escolha explícita, quem atende vê só a própria agenda; "todos" abre
     * a de todos. Quem não atende (recepção, gestor) vê todos por padrão.
     */
    private function professionalFilter(Request $request, int|string|null $chosen): ?int
    {
        if ($chosen === 'todos') {
            return null;
        }

        if ($chosen !== null) {
            return (int) $chosen;
        }

        $user = $request->user();

        return User::query()->attending()->whereKey($user->getKey())->exists() ? $user->getKey() : null;
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
