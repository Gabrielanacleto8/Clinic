<?php

namespace App\Http\Controllers;

use App\Enums\AppointmentStatus;
use App\Enums\AuditAction;
use App\Enums\Permission;
use App\Http\Requests\AppointmentRequest;
use App\Http\Requests\AppointmentStatusRequest;
use App\Models\Appointment;
use App\Models\Patient;
use App\Models\Procedure;
use App\Models\User;
use App\Services\AppointmentScheduler;
use App\Services\AuditLogger;
use App\Support\ClinicTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AppointmentController extends Controller
{
    /** Remarcar desfez a presença confirmada: a mensagem avisa. */
    private bool $unconfirmed = false;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly AppointmentScheduler $scheduler,
    ) {}

    public function create(Request $request): View
    {
        Gate::authorize('create', Appointment::class);

        $query = $request->validate([
            'paciente' => ['nullable', 'integer'],
            'data' => ['nullable', 'date_format:Y-m-d'],
        ]);

        // Paciente vindo do link "Agendar" ou devolvido após erro de validação.
        $patientId = $request->old('patient_id', $query['paciente'] ?? null);

        // Sugere a próxima hora cheia de hoje, ou 9h do dia futuro que estava aberto na agenda.
        $suggested = isset($query['data']) && $query['data'] > ClinicTime::today()
            ? CarbonImmutable::createFromFormat('Y-m-d H:i', $query['data'].' 09:00', ClinicTime::timezone())
            : ClinicTime::local(now())->addHour()->startOfHour();

        $appointment = new Appointment;
        $appointment->schedule($suggested->utc(), 30);
        $appointment->professional_id = $request->user()->hasPermission(Permission::RecordsManage) ? $request->user()->getKey() : null;

        return view('appointments.create', [
            'appointment' => $appointment,
            'patient' => is_numeric($patientId) ? Patient::find((int) $patientId) : null,
            // Vindo do botão "Agendar" da ficha: cancelar volta para ela.
            'fromPatient' => isset($query['paciente']),
            'professionals' => $this->professionals(),
            'procedures' => $this->procedures(),
        ]);
    }

    public function store(AppointmentRequest $request): RedirectResponse
    {
        $appointment = DB::transaction(function () use ($request): Appointment {
            $appointment = new Appointment($request->safe()->only(['notes']));
            $appointment->procedure_id = $request->validated('procedure_id');
            $appointment->schedule(ClinicTime::fromInput($request->validated('scheduled_at')), (int) $request->validated('duration_minutes'));
            $appointment->patient_id = (int) $request->validated('patient_id');
            $appointment->professional_id = (int) $request->validated('professional_id');
            $appointment->status = AppointmentStatus::Scheduled;
            $appointment->created_by = $request->user()->getKey();

            $this->scheduler->save($appointment);

            $this->audit->log(AuditAction::AppointmentCreated, $appointment, ['patient_id' => $appointment->patient_id]);

            return $appointment;
        });

        return $this->backToAgenda($appointment, 'Agendamento criado.');
    }

    public function edit(Appointment $appointment): View
    {
        Gate::authorize('update', $appointment);
        $this->ensurePatientIsActive($appointment);

        return view('appointments.edit', [
            'appointment' => $appointment->load('patient'),
            'patient' => $appointment->patient,
            'professionals' => $this->professionals(),
            'procedures' => $this->procedures($appointment->procedure_id),
        ]);
    }

    public function update(AppointmentRequest $request, Appointment $appointment): RedirectResponse
    {
        $this->ensurePatientIsActive($appointment);

        DB::transaction(function () use ($request, $appointment): void {
            $appointment->fill($request->safe()->only(['notes']));
            $appointment->procedure_id = $request->validated('procedure_id');
            $appointment->schedule(ClinicTime::fromInput($request->validated('scheduled_at')), (int) $request->validated('duration_minutes'));
            $appointment->professional_id = (int) $request->validated('professional_id');

            // Horário ou profissional novos: a presença confirmada era para o anterior.
            if ($appointment->status === AppointmentStatus::Confirmed && $appointment->isDirty(['scheduled_at', 'professional_id'])) {
                $appointment->status = AppointmentStatus::Scheduled;
                $this->unconfirmed = true;
            }

            // Só os nomes dos campos entram na auditoria, nunca os valores.
            $changed = array_values(array_diff(array_keys($appointment->getDirty()), ['ends_at']));

            if ($changed === []) {
                return;
            }

            $this->scheduler->save($appointment);

            $this->audit->log(AuditAction::AppointmentUpdated, $appointment, ['patient_id' => $appointment->patient_id, 'fields' => $changed]);
        });

        return $this->backToAgenda($appointment, $this->unconfirmed
            ? 'Agendamento atualizado. A presença voltou a "Agendado": confirme de novo com o paciente.'
            : 'Agendamento atualizado.');
    }

    /**
     * Confirmar presença, cancelar, marcar falta ou reabrir. "Atendido" não
     * entra aqui: só é definido quando o atendimento é registrado.
     */
    public function status(AppointmentStatusRequest $request, Appointment $appointment): RedirectResponse
    {
        $validated = $request->validated();

        DB::transaction(function () use ($appointment, $validated): void {
            $from = $appointment->status;
            $appointment->status = AppointmentStatus::from($validated['status']);

            // Reabrir passa pela mesma checagem de conflito de um agendamento novo.
            $this->scheduler->save($appointment);

            $this->audit->log(AuditAction::AppointmentStatusChanged, $appointment, [
                'patient_id' => $appointment->patient_id,
                'from' => $from->value,
                'to' => $appointment->status->value,
            ]);
        });

        return $this->backToAgenda($appointment, 'Situação do agendamento atualizada.');
    }

    /**
     * Agendamentos de pacientes arquivados ficam inacessíveis, como o resto do cadastro.
     */
    private function ensurePatientIsActive(Appointment $appointment): void
    {
        abort_unless($appointment->patient()->exists(), 404);
    }

    /**
     * Volta ao dia do agendamento, mantendo a visão e o filtro de
     * profissional que a agenda tinha, e já no painel do dia.
     */
    private function backToAgenda(Appointment $appointment, string $message): RedirectResponse
    {
        $context = request()->validate([
            'visao' => ['nullable', Rule::in(['mes', 'semana'])],
            'profissional' => ['nullable', 'integer'],
        ]);

        return redirect()
            ->to(route('agenda.index', [...array_filter($context), 'data' => $appointment->localDay()]).'#dia')
            ->with('success', $message);
    }

    /**
     * @return Collection<int, Procedure>
     */
    private function procedures(?int $currentId = null): Collection
    {
        return Procedure::query()->selectable($currentId)->orderBy('name')->get(['id', 'name', 'duration_minutes']);
    }

    /**
     * @return Collection<int, User>
     */
    private function professionals(): Collection
    {
        return User::query()->attending()->orderBy('name')->get(['id', 'name']);
    }
}
