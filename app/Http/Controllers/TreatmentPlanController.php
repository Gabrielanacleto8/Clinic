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
 * Orçamentos e pacotes de sessões: a lista do módulo e, dentro da ficha do
 * paciente, criar, alterar (em aberto), consultar e imprimir.
 */
class TreatmentPlanController extends Controller
{
    private const STATUSES = [
        'andamento' => 'Em aberto e aprovados',
        'abertos' => 'Orçamentos em aberto',
        'aprovados' => 'Aprovados (pacotes)',
        'finalizados' => 'Recusados e encerrados',
        'todos' => 'Todos',
    ];

    /** Validade sugerida para um orçamento novo. */
    private const VALIDITY_DAYS = 15;

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
                'aprovados' => [TreatmentPlanStatus::Approved],
                'finalizados' => [TreatmentPlanStatus::Declined, TreatmentPlanStatus::Closed],
                'todos' => TreatmentPlanStatus::cases(),
                default => [TreatmentPlanStatus::Open, TreatmentPlanStatus::Approved],
            })
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        // A lista diz quem comprou quais procedimentos: leitura auditada, só os filtros usados.
        $this->audit->log(AuditAction::TreatmentPlansListed, null, ['filters' => array_keys(array_filter($filters, filled(...)))]);

        return view('plans.index', [
            'plans' => $plans,
            'filters' => ['q' => $filters['q'] ?? '', 'situacao' => $status],
            'statusOptions' => self::STATUSES,
        ]);
    }

    public function create(Patient $patient): View
    {
        Gate::authorize('create', TreatmentPlan::class);
        Gate::authorize('view', $patient);

        $plan = new TreatmentPlan(['valid_until' => ClinicTime::local(now())->addDays(self::VALIDITY_DAYS)->format('Y-m-d')]);

        return view('plans.create', [
            'patient' => $patient,
            'plan' => $plan->setRelation('items', new Collection),
            'procedures' => $this->procedures(),
        ]);
    }

    public function store(TreatmentPlanRequest $request, Patient $patient, TreatmentPlanEditor $editor): RedirectResponse
    {
        Gate::authorize('view', $patient);

        $plan = $editor->create($patient, $request->planData(), $request->items(), $request->user());

        return redirect()->route('patients.plans.show', [$patient, $plan])->with('success', 'Orçamento criado. Imprima para o paciente ou aprove quando ele aceitar.');
    }

    public function show(Request $request, Patient $patient, TreatmentPlan $treatmentPlan): View
    {
        Gate::authorize('view', $treatmentPlan);

        $this->audit->log(AuditAction::TreatmentPlanViewed, $treatmentPlan, ['patient_id' => $patient->getKey()]);

        $user = $request->user();
        $treatmentPlan->load(['creator:id,name', 'approver:id,name', 'closer:id,name']);
        $treatmentPlan->setRelation('items', $treatmentPlan->items()->withUsedSessions()->with('procedure:id,name')->get());

        // As sessões já usadas (data de cada atendimento) são leitura de prontuário.
        if (Gate::allows('viewClinicalRecords', $patient)) {
            $treatmentPlan->items->load(['treatments' => fn ($query) => $query->select(['id', 'patient_id', 'treatment_plan_item_id', 'performed_at', 'voided_at'])->oldest('performed_at')]);
        }

        // A cobrança só é consultada para quem vê o financeiro ou recebe de paciente.
        if ($user->hasPermission(Permission::FinanceView) || $user->hasPermission(Permission::FinanceReceive)) {
            $treatmentPlan->load('financialEntry.installments');
        }

        return view('plans.show', [
            'patient' => $patient,
            'plan' => $treatmentPlan,
            'paymentMethods' => PaymentMethod::options(),
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
     * @return Collection<int, Procedure>
     */
    private function procedures(): Collection
    {
        return Procedure::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'price']);
    }
}
