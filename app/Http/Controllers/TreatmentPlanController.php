<?php

namespace App\Http\Controllers;

use App\Enums\AuditAction;
use App\Enums\PaymentMethod;
use App\Enums\Permission;
use App\Enums\TreatmentPlanStatus;
use App\Http\Requests\TreatmentPlanRequest;
use App\Models\ClinicProfile;
use App\Models\Patient;
use App\Models\Procedure;
use App\Models\TreatmentPlan;
use App\Services\AuditLogger;
use App\Services\TreatmentPlanEditor;
use App\Support\ClinicTime;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Orçamentos e pacotes de sessões: a lista e a criação no módulo e, dentro da
 * ficha do paciente, alterar (em aberto), consultar e imprimir.
 */
class TreatmentPlanController extends Controller
{
    private const STATUSES = [
        'andamento' => 'Em aberto e vendidos',
        'abertos' => 'Orçamentos em aberto',
        'vendidos' => 'Vendidos (pacotes)',
        'finalizados' => 'Recusados e encerrados',
        'todos' => 'Todos',
    ];

    /** Validade sugerida para um orçamento novo. */
    private const VALIDITY_DAYS = 15;

    /** Diálogo "Novo orçamento" da lista; os campos dele levam este "scope". */
    private const CREATE_DIALOG = 'plan-create';

    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): View
    {
        Gate::authorize('viewAny', TreatmentPlan::class);

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'situacao' => ['nullable', Rule::in(array_keys(self::STATUSES))],
        ]);
        $status = $filters['situacao'] ?? 'andamento';

        $plans = TreatmentPlan::query()
            ->with('patient:id,name')
            ->withUsage()
            // Orçamentos de pacientes arquivados não aparecem.
            ->whereHas('patient', fn ($query) => $query->search($filters['q'] ?? null))
            ->whereIn('status', match ($status) {
                'abertos' => [TreatmentPlanStatus::Open],
                'vendidos' => [TreatmentPlanStatus::Approved],
                'finalizados' => [TreatmentPlanStatus::Declined, TreatmentPlanStatus::Closed],
                'todos' => TreatmentPlanStatus::cases(),
                default => [TreatmentPlanStatus::Open, TreatmentPlanStatus::Approved],
            })
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        // A lista diz quem comprou quais procedimentos: leitura auditada, só os filtros usados.
        $this->audit->log(AuditAction::TreatmentPlansListed, null, ['filters' => array_keys(array_filter($filters, filled(...)))]);

        // O paciente é escolhido pela busca de pacientes do diálogo.
        $user = $request->user();
        $planForm = $user->can('create', TreatmentPlan::class) && $user->can('viewAny', Patient::class)
            // Após erro de validação no diálogo, ele reabre com o paciente que estava escolhido.
            ? ['scope' => self::CREATE_DIALOG, ...$this->newPlanForm($request->old('_dialog') === self::CREATE_DIALOG ? $request->old('patient_id') : null)]
            : null;

        // Diálogo "Converter em venda" da própria lista. Depois de um erro de validação,
        // ele reabre apontando para o orçamento que estava sendo convertido.
        $approvalForm = null;

        if ($user->hasPermission(Permission::PlansManage)) {
            $returning = $request->old('_dialog') === 'plan-approval' && is_numeric($request->old('plan_id'))
                ? TreatmentPlan::query()->with('patient:id,name')->whereHas('patient')->find((int) $request->old('plan_id'))
                : null;

            $approvalForm = [
                'plan' => $returning && $user->can('approve', $returning) ? $returning : null,
                'paymentMethods' => PaymentMethod::options(),
            ];
        }

        return view('plans.index', [
            'plans' => $plans,
            'filters' => ['q' => $filters['q'] ?? '', 'situacao' => $status],
            'statusOptions' => self::STATUSES,
            'planForm' => $planForm,
            'approvalForm' => $approvalForm,
        ]);
    }

    public function create(Request $request): View
    {
        Gate::authorize('create', TreatmentPlan::class);
        // O paciente é escolhido pela busca de pacientes do formulário.
        Gate::authorize('viewAny', Patient::class);

        $query = $request->validate(['paciente' => ['nullable', 'integer']]);

        return view('plans.create', [
            // Paciente vindo da ficha ("Novo orçamento") ou devolvido após erro de validação.
            ...$this->newPlanForm($request->old('patient_id', $query['paciente'] ?? null)),
            // Vindo da ficha: voltar e cancelar levam a ela.
            'fromPatient' => isset($query['paciente']),
        ]);
    }

    public function store(TreatmentPlanRequest $request, TreatmentPlanEditor $editor): RedirectResponse
    {
        $patient = $request->patient();
        Gate::authorize('view', $patient);

        $plan = $editor->create($patient, $request->planData(), $request->items(), $request->user());

        // "Criar e converter em venda": a tela do orçamento já abre a conversão, que pede a forma de pagamento.
        if ($request->convertsToSale()) {
            return redirect()->route('patients.plans.show', ['patient' => $patient, 'treatmentPlan' => $plan, 'vender' => 1])
                ->with('success', 'Orçamento criado. Confirme a forma de pagamento para converter em venda.');
        }

        return redirect()->route('patients.plans.show', [$patient, $plan])->with('success', 'Orçamento criado. Imprima para o paciente ou converta em venda quando ele aceitar.');
    }

    public function show(Request $request, Patient $patient, TreatmentPlan $treatmentPlan): View
    {
        Gate::authorize('view', $treatmentPlan);

        $query = $request->validate(['vender' => ['nullable', 'boolean']]);

        $this->audit->log(AuditAction::TreatmentPlanViewed, $treatmentPlan, ['patient_id' => $patient->getKey()]);

        $this->loadDetails($request, $patient, $treatmentPlan);

        return view('plans.show', [
            'patient' => $patient,
            'plan' => $treatmentPlan,
            'paymentMethods' => PaymentMethod::options(),
            'opensConversion' => (bool) ($query['vender'] ?? false),
        ]);
    }

    /**
     * Detalhes para a janela da lista de orçamentos: os mesmos da página, sem as
     * ações que mudam a situação. É leitura do orçamento e é auditada como a página.
     */
    public function summary(Request $request, Patient $patient, TreatmentPlan $treatmentPlan): View
    {
        Gate::authorize('view', $treatmentPlan);

        $this->audit->log(AuditAction::TreatmentPlanViewed, $treatmentPlan, ['patient_id' => $patient->getKey(), 'view' => 'summary']);

        $this->loadDetails($request, $patient, $treatmentPlan);

        return view('plans._summary', [
            'patient' => $patient,
            'plan' => $treatmentPlan,
        ]);
    }

    public function edit(Patient $patient, TreatmentPlan $treatmentPlan): View
    {
        Gate::authorize('update', $treatmentPlan);

        return view('plans.edit', [
            'patient' => $patient,
            'plan' => $treatmentPlan->load('items'),
            'procedures' => $this->procedures(),
        ]);
    }

    public function update(TreatmentPlanRequest $request, Patient $patient, TreatmentPlan $treatmentPlan, TreatmentPlanEditor $editor): RedirectResponse
    {
        $editor->update($treatmentPlan, $request->planData(), $request->items());

        return redirect()->route('patients.plans.show', [$patient, $treatmentPlan])->with('success', 'Orçamento atualizado.');
    }

    /**
     * Orçamento para entregar ao paciente, com a identificação da clínica.
     */
    public function print(Patient $patient, TreatmentPlan $treatmentPlan): View
    {
        Gate::authorize('view', $treatmentPlan);

        $this->audit->log(AuditAction::TreatmentPlanPrinted, $treatmentPlan, ['patient_id' => $patient->getKey()]);

        return view('plans.print', [
            'patient' => $patient,
            'plan' => $treatmentPlan->load(['items.procedure:id,name', 'creator:id,name']),
            'clinic' => ClinicProfile::current(),
        ]);
    }

    /**
     * O que a página e a janela do orçamento exibem: itens com as sessões usadas e,
     * conforme as permissões, as datas das sessões e a cobrança.
     */
    private function loadDetails(Request $request, Patient $patient, TreatmentPlan $plan): void
    {
        $plan->setRelation('items', $plan->items()->withUsedSessions()->with('procedure:id,name')->get());

        // As sessões já usadas (data de cada atendimento) são leitura de prontuário.
        if (Gate::allows('viewClinicalRecords', $patient)) {
            $plan->items->load(['treatments' => fn ($query) => $query->select(['id', 'patient_id', 'treatment_plan_item_id', 'performed_at', 'voided_at'])->oldest('performed_at')]);
        }

        // A cobrança só é consultada para quem vê o financeiro ou recebe de paciente.
        $user = $request->user();

        if ($user->hasPermission(Permission::FinanceView) || $user->hasPermission(Permission::FinanceReceive)) {
            $plan->load('financialEntry.installments');
        }
    }

    /**
     * Dados do formulário de orçamento novo, na página ou no diálogo da lista.
     *
     * @return array{plan: TreatmentPlan, patient: ?Patient, procedures: Collection<int, Procedure>}
     */
    private function newPlanForm(mixed $patientId): array
    {
        $plan = new TreatmentPlan(['valid_until' => ClinicTime::local(now())->addDays(self::VALIDITY_DAYS)->format('Y-m-d')]);

        return [
            'plan' => $plan->setRelation('items', new Collection),
            // Arquivado não é encontrado: o paciente volta vazio.
            'patient' => is_numeric($patientId) ? Patient::query()->select(['id', 'name'])->find((int) $patientId) : null,
            'procedures' => $this->procedures(),
        ];
    }

    /**
     * @return Collection<int, Procedure>
     */
    private function procedures(): Collection
    {
        return Procedure::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'price']);
    }
}
