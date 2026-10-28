<?php

namespace App\Http\Controllers;

use App\Enums\AppointmentStatus;
use App\Enums\AuditAction;
use App\Enums\Permission;
use App\Http\Requests\PatientRequest;
use App\Models\Appointment;
use App\Models\FinancialInstallment;
use App\Models\Patient;
use App\Models\PatientAttachment;
use App\Models\TreatmentNote;
use App\Models\TreatmentPlan;
use App\Models\TreatmentProduct;
use App\Services\AppointmentScheduler;
use App\Services\AuditLogger;
use App\Support\ClinicTime;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PatientController extends Controller
{
    private const CLINICAL_TABS = [
        'atendimentos' => 'Atendimentos',
        'produtos' => 'Produtos utilizados',
        'fotos' => 'Fotos',
        'evolucoes' => 'Evoluções',
    ];

    private const AGENDA_TABS = ['agendamentos' => 'Agendamentos'];

    private const FINANCE_TABS = ['financeiro' => 'Financeiro'];

    private const PLAN_TABS = ['orcamentos' => 'Orçamentos'];

    /** Abrir a mesma aba de novo em poucos minutos não gera outro registro de leitura. */
    private const VIEW_AUDIT_MINUTES = 5;

    private const SEARCH_LIMIT = 8;

    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Patient::class);

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        $archived = $request->boolean('arquivados');

        if ($archived) {
            Gate::authorize('viewArchived', Patient::class);
        }

        $patients = Patient::query()
            ->when($archived, fn ($query) => $query->onlyTrashed())
            ->search($filters['q'] ?? null)
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return view('patients.index', [
            'patients' => $patients,
            'search' => $filters['q'] ?? '',
            'archived' => $archived,
        ]);
    }

    /**
     * Busca usada pelo seletor de paciente do agendamento. Devolve o mínimo
     * para identificar a pessoa: nome, CPF parcialmente oculto, nascimento e
     * telefone. Busca um a mais do que mostra, para avisar que a lista foi cortada.
     */
    public function search(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Patient::class);

        $filters = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:100'],
        ]);

        $patients = Patient::query()->search($filters['q'])->orderBy('name')->limit(self::SEARCH_LIMIT + 1)->get();
        $hasMore = $patients->count() > self::SEARCH_LIMIT;

        return response()
            ->json($patients->take(self::SEARCH_LIMIT)->map(fn (Patient $patient) => [
                'id' => $patient->id,
                'name' => $patient->name,
                'cpf' => $patient->maskedCpf(),
                'birth_date' => $patient->birth_date->format('d/m/Y'),
                'phone' => $patient->formattedPhone(),
                'minor' => $patient->isMinor(),
            ])->values())
            ->header('X-Has-More', $hasMore ? '1' : '0');
    }

    public function create(): View
    {
        Gate::authorize('create', Patient::class);

        return view('patients.create', ['patient' => new Patient]);
    }

    public function store(PatientRequest $request): RedirectResponse
    {
        $patient = $this->saveGuardingCpf(function () use ($request): Patient {
            $patient = new Patient($request->patientData());
            $patient->created_by = $request->user()->getKey();
            $this->applyImageUseConsent($patient, $request->imageUseConsent());
            $patient->save();

            $this->audit->log(AuditAction::PatientCreated, $patient);

            return $patient;
        });

        return redirect()->route('patients.show', $patient)->with('success', 'Paciente cadastrado com sucesso.');
    }

    public function show(Request $request, Patient $patient): View
    {
        Gate::authorize('view', $patient);

        $user = $request->user();

        // Recepção vê só o cadastro; o prontuário nem chega a ser consultado.
        $clinical = Gate::allows('viewClinicalRecords', $patient);
        $agenda = $user->hasPermission(Permission::AgendaView);
        // Quem recebe de paciente vê o débito dele, mesmo sem o resto do financeiro.
        $finance = $user->hasPermission(Permission::FinanceView) || $user->hasPermission(Permission::FinanceReceive);
        $tabs = [...$this->availableTabs($clinical, $agenda, $finance), ...($user->can('viewAny', TreatmentPlan::class) ? self::PLAN_TABS : [])];
        $requested = $request->query('aba');
        $tab = is_string($requested) && array_key_exists($requested, $tabs) ? $requested : array_key_first($tabs);

        $this->auditView($request, $patient, $tab);

        return view('patients.show', [
            'patient' => $patient->load($clinical ? ['creator', 'anamnesis.updater:id,name'] : ['creator']),
            'tabs' => $tabs,
            'tab' => $tab,
            ...($agenda ? $this->agendaSummary($patient, $clinical) : []),
            'financialSummary' => $finance ? $this->financialSummary($patient) : null,
            // Só a aba aberta vai ao banco.
            ...($tab === null ? [] : $this->tabData($tab, $patient)),
        ]);
    }

    /**
     * Cada troca de aba recarrega a ficha: registrar a leitura uma vez por aba
     * a cada poucos minutos deixa a trilha legível sem perder nenhum acesso.
     */
    private function auditView(Request $request, Patient $patient, ?string $tab): void
    {
        $key = 'patient_viewed.'.$patient->getKey().'.'.($tab ?? 'cadastro');
        $last = $request->session()->get($key);

        if (is_int($last) && $last > now()->subMinutes(self::VIEW_AUDIT_MINUTES)->getTimestamp()) {
            return;
        }

        $request->session()->put($key, now()->getTimestamp());
        $this->audit->log(AuditAction::PatientViewed, $patient, $tab === null ? [] : ['tab' => $tab]);
    }

    /**
     * O que a ficha mostra da agenda: os próximos horários em aberto, as
     * faltas e, sem horário marcado, o retorno que o último atendimento previu.
     *
     * @return array<string, mixed>
     */
    private function agendaSummary(Patient $patient, bool $clinical): array
    {
        $upcoming = $patient->appointments()
            ->with(['procedure:id,name', 'professional:id,name'])
            ->whereIn('status', AppointmentStatus::open())
            ->where('scheduled_at', '>=', now())
            ->orderBy('scheduled_at')
            ->limit(3)
            ->get();

        $pendingReturn = null;

        if ($clinical && $upcoming->isEmpty()) {
            $pendingReturn = $patient->treatments()->notVoided()->whereNotNull('return_date')->latest('performed_at')->latest('id')->value('return_date');
        }

        return [
            'upcomingAppointments' => $upcoming,
            'noShowCount' => $patient->appointments()->where('status', AppointmentStatus::NoShow->value)->count(),
            'pendingReturn' => $pendingReturn,
        ];
    }

    /**
     * Quanto o paciente deve, separando o que já venceu. Uma consulta só.
     */
    private function financialSummary(Patient $patient): object
    {
        return DB::table('financial_installments as i')
            ->join('financial_entries as e', 'e.id', '=', 'i.financial_entry_id')
            ->where('e.patient_id', $patient->getKey())
            ->whereNull('i.paid_at')
            ->selectRaw('coalesce(sum(i.amount), 0) as pending')
            ->selectRaw('coalesce(sum(case when i.due_date < ? then i.amount end), 0) as overdue', [ClinicTime::today()])
            ->first();
    }

    /**
     * Abas do histórico que o usuário pode ver, na ordem de exibição.
     *
     * @return array<string, string>
     */
    private function availableTabs(bool $clinical, bool $agenda, bool $finance): array
    {
        return [
            ...($clinical ? self::CLINICAL_TABS : []),
            ...($agenda ? self::AGENDA_TABS : []),
            ...($finance ? self::FINANCE_TABS : []),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function tabData(string $tab, Patient $patient): array
    {
        $ofPatient = fn ($query) => $query->where('treatments.patient_id', $patient->getKey())->whereNull('treatments.deleted_at');

        $page = fn ($query, int $size = 15) => $query->paginate($size)->withQueryString()->fragment('historico');

        return match ($tab) {
            'orcamentos' => [
                'records' => $page($patient->treatmentPlans()->withUsage()->latest('id')),
            ],
            'atendimentos' => [
                'records' => $page($patient->treatments()->with(['professional:id,name', 'procedure:id,name'])->withCount('photos')
                    ->latest('performed_at')),
            ],
            'agendamentos' => [
                'records' => $page($patient->appointments()->with(['professional:id,name', 'procedure:id,name'])
                    ->latest('scheduled_at')->latest('id')),
            ],
            'produtos' => [
                'records' => $page(TreatmentProduct::query()
                    ->select('treatment_products.*')
                    ->join('treatments', 'treatments.id', '=', 'treatment_products.treatment_id')
                    ->where($ofPatient)
                    // Produto de atendimento anulado voltou ao estoque: não foi aplicado.
                    ->whereNull('treatments.voided_at')
                    ->with(['batch.product:id,name,unit', 'treatment:id,patient_id,procedure_id,performed_at', 'treatment.procedure:id,name'])
                    ->orderByDesc('treatments.performed_at')
                    ->orderBy('treatment_products.id')),
            ],
            'fotos' => [
                // Avulsas e de atendimento juntas, da mais recente à mais antiga. O dia
                // da foto de atendimento é o do atendimento no fuso da clínica, como a tela mostra.
                'records' => $page(PatientAttachment::query()
                    ->select('patient_attachments.*')
                    ->where('patient_attachments.patient_id', $patient->getKey())
                    ->photos()
                    ->leftJoin('treatments', 'treatments.id', '=', 'patient_attachments.treatment_id')
                    ->where(fn ($query) => $query->whereNull('patient_attachments.treatment_id')->orWhereNull('treatments.deleted_at'))
                    ->with(['treatment:id,patient_id,procedure_id,performed_at,voided_at', 'treatment.procedure:id,name'])
                    ->orderByRaw("coalesce(patient_attachments.taken_on, date(convert_tz(treatments.performed_at, '+00:00', ?))) desc", [ClinicTime::utcOffset()])
                    ->orderByDesc('patient_attachments.id'), 24),
            ],
            'evolucoes' => [
                'records' => $page(TreatmentNote::query()
                    ->select('treatment_notes.*')
                    ->join('treatments', 'treatments.id', '=', 'treatment_notes.treatment_id')
                    ->where($ofPatient)
                    ->with(['author:id,name', 'treatment:id,patient_id,procedure_id,performed_at', 'treatment.procedure:id,name'])
                    ->orderByDesc('treatment_notes.id')),
            ],
            'financeiro' => [
                'records' => $page(FinancialInstallment::query()
                    ->select('financial_installments.*')
                    ->join('financial_entries', 'financial_entries.id', '=', 'financial_installments.financial_entry_id')
                    ->where('financial_entries.patient_id', $patient->getKey())
                    // Total e número de parcelas alimentam o botão "Receber" sem consulta por linha.
                    ->with(['entry' => fn ($query) => $query->withCount('installments')->withSum('installments as total_amount', 'amount')])
                    ->orderByDesc('due_date')
                    ->orderByDesc('financial_installments.id')),
                // Vencido e a vencer separados: o que cobrar agora e o que ainda vai vencer.
                'financialTotals' => DB::table('financial_installments as i')
                    ->join('financial_entries as e', 'e.id', '=', 'i.financial_entry_id')
                    ->where('e.patient_id', $patient->getKey())
                    ->selectRaw('coalesce(sum(case when i.paid_at is not null then i.amount end), 0) as paid')
                    ->selectRaw('coalesce(sum(case when i.paid_at is null then i.amount end), 0) as pending')
                    ->selectRaw('coalesce(sum(case when i.paid_at is null and i.due_date < ? then i.amount end), 0) as overdue', [ClinicTime::today()])
                    ->selectRaw('coalesce(sum(case when i.paid_at is null and i.due_date >= ? then i.amount end), 0) as upcoming', [ClinicTime::today()])
                    ->first(),
            ],
        };
    }

    public function edit(Request $request, Patient $patient): View
    {
        Gate::authorize('update', $patient);

        // O formulário de edição também exibe os dados completos do paciente.
        $this->audit->log(AuditAction::PatientViewed, $patient, ['context' => 'edit']);

        return view('patients.edit', [
            'patient' => $patient,
            // Arquivar fica no fim da edição; o aviso diz quanto o paciente ainda deve.
            'financialSummary' => $request->user()->hasPermission(Permission::FinanceView) || $request->user()->hasPermission(Permission::FinanceReceive)
                ? $this->financialSummary($patient)
                : null,
        ]);
    }

    public function update(PatientRequest $request, Patient $patient): RedirectResponse
    {
        $this->saveGuardingCpf(function () use ($request, $patient): Patient {
            $patient->fill($request->patientData());
            $this->applyImageUseConsent($patient, $request->imageUseConsent());

            // Só os nomes dos campos entram na auditoria, nunca os valores.
            $changed = array_keys($patient->getDirty());

            if ($changed !== []) {
                $patient->save();

                $this->audit->log(AuditAction::PatientUpdated, $patient, ['fields' => $changed]);
            }

            return $patient;
        });

        return redirect()->route('patients.show', $patient)->with('success', 'Dados do paciente atualizados.');
    }

    public function destroy(Patient $patient, AppointmentScheduler $scheduler): RedirectResponse
    {
        Gate::authorize('delete', $patient);

        $cancelled = DB::transaction(function () use ($patient, $scheduler): int {
            $patient->delete();

            $this->audit->log(AuditAction::PatientArchived, $patient);

            // Agendamento de paciente arquivado some da agenda: em aberto, ele seguiria
            // ocupando o horário do profissional sem ninguém ver nem poder cancelar.
            $open = Appointment::query()
                ->where('patient_id', $patient->getKey())
                ->whereIn('status', AppointmentStatus::open())
                ->lockForUpdate()
                ->get();

            foreach ($open as $appointment) {
                $from = $appointment->status;
                $appointment->status = AppointmentStatus::Cancelled;
                $scheduler->save($appointment);

                $this->audit->log(AuditAction::AppointmentStatusChanged, $appointment, [
                    'patient_id' => $patient->getKey(),
                    'from' => $from->value,
                    'to' => AppointmentStatus::Cancelled->value,
                    'reason' => 'patient_archived',
                ]);
            }

            return $open->count();
        });

        $message = match ($cancelled) {
            0 => 'Paciente arquivado.',
            1 => 'Paciente arquivado. O agendamento em aberto dele foi cancelado.',
            default => "Paciente arquivado. Os {$cancelled} agendamentos em aberto dele foram cancelados.",
        };

        return redirect()->route('patients.index')->with('success', $message);
    }

    public function restore(Patient $patient): RedirectResponse
    {
        Gate::authorize('restore', $patient);

        DB::transaction(function () use ($patient): void {
            $patient->restore();

            $this->audit->log(AuditAction::PatientRestored, $patient);
        });

        return redirect()->route('patients.show', $patient)->with('success', 'Paciente restaurado.');
    }

    /**
     * Autorizar grava quando; retirar a autorização apaga a data. Marcar de
     * novo quem já autorizou mantém a data original.
     */
    private function applyImageUseConsent(Patient $patient, ?bool $consent): void
    {
        if ($consent === true && $patient->image_use_consented_at === null) {
            $patient->image_use_consented_at = now();
        } elseif ($consent === false) {
            $patient->image_use_consented_at = null;
        }
    }

    /**
     * Grava em transação e converte a violação do índice único de CPF em erro
     * de validação. Cobre a corrida entre dois cadastros simultâneos, que a
     * checagem do Form Request sozinha não impede.
     *
     * @param  callable(): Patient  $callback
     */
    private function saveGuardingCpf(callable $callback): Patient
    {
        try {
            return DB::transaction($callback);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['cpf' => PatientRequest::CPF_TAKEN_MESSAGE]);
        }
    }
}
