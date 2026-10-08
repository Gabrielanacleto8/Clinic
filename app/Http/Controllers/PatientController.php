<?php

namespace App\Http\Controllers;

use App\Enums\AttachmentType;
use App\Enums\AuditAction;
use App\Enums\Permission;
use App\Enums\TreatmentStatus;
use App\Http\Requests\PatientRequest;
use App\Models\FinancialInstallment;
use App\Models\Patient;
use App\Models\TreatmentAttachment;
use App\Models\TreatmentNote;
use App\Models\TreatmentProduct;
use App\Models\User;
use App\Services\AuditLogger;
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

    private const FINANCE_TABS = ['financeiro' => 'Financeiro'];

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
     * para identificar a pessoa: nome, CPF parcialmente oculto e nascimento.
     */
    public function search(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Patient::class);

        $filters = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:100'],
        ]);

        $patients = Patient::query()->search($filters['q'])->orderBy('name')->limit(8)->get();

        return response()->json($patients->map(fn (Patient $patient) => [
            'id' => $patient->id,
            'name' => $patient->name,
            'cpf' => $patient->maskedCpf(),
            'birth_date' => $patient->birth_date->format('d/m/Y'),
        ])->values());
    }

    public function create(): View
    {
        Gate::authorize('create', Patient::class);

        return view('patients.create', ['patient' => new Patient]);
    }

    public function store(PatientRequest $request): RedirectResponse
    {
        $patient = $this->saveGuardingCpf(function () use ($request): Patient {
            $patient = new Patient($request->validated());
            $patient->created_by = $request->user()->getKey();
            $patient->save();

            $this->audit->log(AuditAction::PatientCreated, $patient);

            return $patient;
        });

        return redirect()->route('patients.show', $patient)->with('success', 'Paciente cadastrado com sucesso.');
    }

    public function show(Request $request, Patient $patient): View
    {
        Gate::authorize('view', $patient);

        $this->audit->log(AuditAction::PatientViewed, $patient);

        // Recepção vê só o cadastro; o prontuário nem chega a ser consultado.
        $clinical = Gate::allows('viewClinicalRecords', $patient);
        $tabs = $this->availableTabs($request->user(), $clinical);
        $tab = array_key_exists((string) $request->query('aba'), $tabs) ? $request->query('aba') : array_key_first($tabs);

        return view('patients.show', [
            'patient' => $patient->load($clinical ? ['creator', 'anamnesis'] : ['creator']),
            'tabs' => $tabs,
            'tab' => $tab,
            // Só a aba aberta vai ao banco.
            ...($tab === null ? [] : $this->tabData($tab, $patient)),
        ]);
    }

    /**
     * Abas do histórico que o usuário pode ver, na ordem de exibição.
     *
     * @return array<string, string>
     */
    private function availableTabs(User $user, bool $clinical): array
    {
        return [
            ...($clinical ? self::CLINICAL_TABS : []),
            ...($user->hasPermission(Permission::FinanceView) ? self::FINANCE_TABS : []),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function tabData(string $tab, Patient $patient): array
    {
        $ofPatient = fn ($query) => $query->where('treatments.patient_id', $patient->getKey())->whereNull('treatments.deleted_at');

        return match ($tab) {
            'atendimentos' => [
                'records' => $patient->treatments()->with(['professional:id,name', 'procedure:id,name'])->withCount('photos')
                    ->latest('performed_at')->paginate(10)->withQueryString(),
            ],
            // Só o que foi de fato aplicado: produtos de atendimentos finalizados.
            'produtos' => [
                'records' => TreatmentProduct::query()
                    ->select('treatment_products.*')
                    ->join('treatments', 'treatments.id', '=', 'treatment_products.treatment_id')
                    ->where($ofPatient)
                    ->where('treatments.status', TreatmentStatus::Finalized)
                    ->with(['batch.product:id,name,unit', 'treatment:id,patient_id,procedure_id,performed_at', 'treatment.procedure:id,name'])
                    ->orderByDesc('treatments.performed_at')
                    ->orderBy('treatment_products.id')
                    ->paginate(15)->withQueryString(),
            ],
            'fotos' => [
                'records' => TreatmentAttachment::query()
                    ->select('treatment_attachments.*')
                    ->join('treatments', 'treatments.id', '=', 'treatment_attachments.treatment_id')
                    ->where($ofPatient)
                    ->where('treatment_attachments.type', AttachmentType::Photo)
                    ->with(['treatment:id,patient_id,procedure_id,performed_at', 'treatment.procedure:id,name'])
                    ->orderByDesc('treatments.performed_at')
                    ->orderBy('treatment_attachments.id')
                    ->paginate(24)->withQueryString(),
            ],
            'evolucoes' => [
                'records' => TreatmentNote::query()
                    ->select('treatment_notes.*')
                    ->join('treatments', 'treatments.id', '=', 'treatment_notes.treatment_id')
                    ->where($ofPatient)
                    ->with(['author:id,name', 'treatment:id,patient_id,procedure_id,performed_at', 'treatment.procedure:id,name'])
                    ->orderByDesc('treatment_notes.id')
                    ->paginate(15)->withQueryString(),
            ],
            'financeiro' => [
                'records' => FinancialInstallment::query()
                    ->select('financial_installments.*')
                    ->join('financial_entries', 'financial_entries.id', '=', 'financial_installments.financial_entry_id')
                    ->where('financial_entries.patient_id', $patient->getKey())
                    ->with(['entry' => fn ($query) => $query->withCount('installments')])
                    ->orderByDesc('due_date')
                    ->orderByDesc('financial_installments.id')
                    ->paginate(15)->withQueryString(),
                'financialTotals' => DB::table('financial_installments as i')
                    ->join('financial_entries as e', 'e.id', '=', 'i.financial_entry_id')
                    ->where('e.patient_id', $patient->getKey())
                    ->selectRaw('coalesce(sum(case when i.paid_at is not null then i.amount end), 0) as paid')
                    ->selectRaw('coalesce(sum(case when i.paid_at is null then i.amount end), 0) as pending')
                    ->first(),
            ],
        };
    }

    public function edit(Patient $patient): View
    {
        Gate::authorize('update', $patient);

        // O formulário de edição também exibe os dados completos do paciente.
        $this->audit->log(AuditAction::PatientViewed, $patient, ['context' => 'edit']);

        return view('patients.edit', ['patient' => $patient]);
    }

    public function update(PatientRequest $request, Patient $patient): RedirectResponse
    {
        $this->saveGuardingCpf(function () use ($request, $patient): Patient {
            $patient->fill($request->validated());

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

    public function destroy(Patient $patient): RedirectResponse
    {
        Gate::authorize('delete', $patient);

        DB::transaction(function () use ($patient): void {
            $patient->delete();

            $this->audit->log(AuditAction::PatientArchived, $patient);
        });

        return redirect()->route('patients.index')->with('success', 'Paciente arquivado.');
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
