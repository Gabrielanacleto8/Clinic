<?php

namespace Tests\Feature;

use App\Enums\AuditAction;
use App\Enums\FinancialCategory;
use App\Enums\PaymentMethod;
use App\Enums\TreatmentPlanStatus;
use App\Models\AuditLog;
use App\Models\ClinicProfile;
use App\Models\FinancialEntry;
use App\Models\Patient;
use App\Models\Procedure;
use App\Models\Treatment;
use App\Models\TreatmentPlan;
use App\Models\TreatmentPlanItem;
use App\Models\User;
use App\Services\RolePermissions;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Orçamentos e pacotes de sessões: criar, aprovar (gera a cobrança), usar as
 * sessões no registro do atendimento e encerrar.
 */
class TreatmentPlanTest extends TestCase
{
    use RefreshDatabase;

    private User $professional;

    private Patient $patient;

    private Procedure $microneedling;

    private Procedure $peeling;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-08 13:00', 'America/Sao_Paulo'));
        $this->professional = User::factory()->professional()->create(['name' => 'Dra. Helena']);
        $this->patient = Patient::factory()->create(['name' => 'Mariana Costa']);
        $this->microneedling = $this->procedure('Microagulhamento', ['price' => 450]);
        $this->peeling = $this->procedure('Peeling químico', ['price' => 380]);
    }

    private function plansUrl(?Patient $patient = null): string
    {
        return '/pacientes/'.($patient ?? $this->patient)->id.'/orcamentos';
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function createPlan(array $items = [], array $overrides = []): TreatmentPlan
    {
        $this->actingAs($this->professional)->post($this->plansUrl(), [
            'valid_until' => '2026-10-23',
            'notes' => 'Intervalo de 30 dias entre sessões.',
            'items' => $items ?: [
                ['procedure_id' => $this->microneedling->id, 'sessions' => '4', 'unit_price' => '400,00'],
                ['procedure_id' => $this->peeling->id, 'sessions' => '1', 'unit_price' => '380,00'],
                ['procedure_id' => '', 'sessions' => '', 'unit_price' => ''],
            ],
            ...$overrides,
        ])->assertSessionHasNoErrors();

        return TreatmentPlan::query()->latest('id')->firstOrFail();
    }

    private function approve(TreatmentPlan $plan, array $overrides = []): TestResponse
    {
        return $this->post($this->plansUrl()."/{$plan->id}/aprovacao", [
            'payment_method' => PaymentMethod::CreditCard->value,
            'installments' => '3',
            'first_due_date' => '2026-10-08',
            ...$overrides,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function treatment(TreatmentPlanItem $item, array $overrides = []): array
    {
        return [
            'performed_at' => '2026-10-08T12:00',
            'procedure_id' => $item->procedure_id,
            'description' => 'Sessão do pacote.',
            'treatment_plan_item_id' => $item->id,
            'amount' => '450,00',
            'payment_method' => PaymentMethod::Pix->value,
            'installments' => 1,
            'first_due_date' => '2026-10-08',
            ...$overrides,
        ];
    }

    private function remaining(TreatmentPlanItem $item): int
    {
        return TreatmentPlanItem::query()->withUsedSessions()->findOrFail($item->id)->remainingSessions();
    }

    public function test_plan_is_created_with_items_and_encrypted_notes(): void
    {
        $plan = $this->createPlan();

        $this->assertSame(TreatmentPlanStatus::Open, $plan->status);
        $this->assertSame($this->professional->id, $plan->created_by);
        $this->assertSame([[$this->microneedling->id, 4, '400.00'], [$this->peeling->id, 1, '380.00']],
            $plan->items->map(fn ($item) => [$item->procedure_id, $item->sessions, $item->unit_price])->all());
        $this->assertSame('1980.00', $plan->total());
        $this->assertStringNotContainsString('Intervalo', (string) DB::table('treatment_plans')->value('notes'));
        $this->assertSame(2, AuditLog::where('action', AuditAction::TreatmentPlanCreated->value)->sole()->metadata['items']);

        $this->get($this->plansUrl()."/{$plan->id}")->assertOk()->assertSee('Orçamento nº '.$plan->id)->assertSee('R$ 1.980,00');
        $this->get("/pacientes/{$this->patient->id}?aba=orcamentos")->assertOk()->assertSee('Microagulhamento (4)');
    }

    public function test_plan_needs_items_without_repetition_from_active_procedures(): void
    {
        $this->actingAs($this->professional);
        $inactive = $this->procedure('Antigo', ['is_active' => false]);

        $this->post($this->plansUrl(), ['items' => [['procedure_id' => '', 'sessions' => '', 'unit_price' => '']]])->assertSessionHasErrors('items');
        $this->post($this->plansUrl(), ['items' => [
            ['procedure_id' => $this->peeling->id, 'sessions' => '1', 'unit_price' => '380'],
            ['procedure_id' => $this->peeling->id, 'sessions' => '2', 'unit_price' => '380'],
        ]])->assertSessionHasErrors('items');
        $this->post($this->plansUrl(), ['items' => [['procedure_id' => $inactive->id, 'sessions' => '1', 'unit_price' => '10']]])->assertSessionHasErrors('items.0.procedure_id');
        $this->post($this->plansUrl(), ['items' => [['procedure_id' => $this->peeling->id, 'sessions' => '0', 'unit_price' => '-1']]])->assertSessionHasErrors(['items.0.sessions', 'items.0.unit_price']);

        $this->assertSame(0, TreatmentPlan::count());
    }

    public function test_open_plan_is_edited_and_approval_generates_one_charge(): void
    {
        $plan = $this->createPlan();

        $this->put($this->plansUrl()."/{$plan->id}", [
            'valid_until' => '2026-10-30',
            'items' => [['procedure_id' => $this->microneedling->id, 'sessions' => '3', 'unit_price' => '420,00']],
        ])->assertRedirect($this->plansUrl()."/{$plan->id}");
        $this->assertSame('1260.00', $plan->fresh()->load('items')->total());

        $this->approve($plan)->assertRedirect($this->plansUrl()."/{$plan->id}")->assertSessionHasNoErrors();

        $plan->refresh();
        $this->assertSame(TreatmentPlanStatus::Approved, $plan->status);
        $this->assertSame($this->professional->id, $plan->approved_by);

        $entry = FinancialEntry::query()->where('treatment_plan_id', $plan->id)->sole();
        $this->assertSame(FinancialCategory::TreatmentPlan, $entry->category);
        $this->assertSame($this->patient->id, $entry->patient_id);
        $this->assertSame('1260.00', number_format((float) $entry->installments()->sum('amount'), 2, '.', ''));
        $this->assertSame(3, $entry->installments()->count());
        // O profissional não tem a permissão de receber: a cobrança nasce pendente.
        $this->assertSame(0, $entry->installments()->whereNotNull('paid_at')->count());

        // Aprovado vira histórico: não se edita nem se aprova de novo.
        $this->get($this->plansUrl()."/{$plan->id}/editar")->assertForbidden();
        $this->approve($plan)->assertForbidden();
        $this->assertSame(1, FinancialEntry::count());

        // A cobrança do pacote não é excluída à mão no financeiro.
        $this->actingAs(User::factory()->admin()->create())->delete("/financeiro/lancamentos/{$entry->id}")->assertForbidden();
    }

    public function test_free_package_creates_no_charge_and_expired_quote_is_not_approved(): void
    {
        $free = $this->createPlan([['procedure_id' => $this->peeling->id, 'sessions' => '1', 'unit_price' => '0']]);
        $this->approve($free, ['installments' => '1'])->assertSessionHasNoErrors();
        $this->assertSame(TreatmentPlanStatus::Approved, $free->fresh()->status);
        $this->assertSame(0, FinancialEntry::count());

        $expired = $this->createPlan(overrides: ['valid_until' => '2026-10-01']);
        $this->approve($expired)->assertSessionHasErrors('payment_method');
        $this->assertSame(TreatmentPlanStatus::Open, $expired->fresh()->status);
        $this->get($this->plansUrl()."/{$expired->id}")->assertSee('Validade vencida');
    }

    public function test_quote_can_be_declined_and_package_can_be_closed(): void
    {
        $quote = $this->createPlan();
        $this->post($this->plansUrl()."/{$quote->id}/encerramento")->assertForbidden();
        $this->post($this->plansUrl()."/{$quote->id}/recusa")->assertRedirect();
        $this->assertSame(TreatmentPlanStatus::Declined, $quote->fresh()->status);
        $this->approve($quote)->assertForbidden();

        $package = $this->createPlan();
        $this->approve($package);
        $this->post($this->plansUrl()."/{$package->id}/recusa")->assertForbidden();
        $this->post($this->plansUrl()."/{$package->id}/encerramento")->assertRedirect();
        $this->assertSame(TreatmentPlanStatus::Closed, $package->fresh()->status);

        // Sessão de pacote encerrado não pode mais ser usada.
        $item = $package->items()->first();
        $this->post("/pacientes/{$this->patient->id}/atendimentos", $this->treatment($item))->assertSessionHasErrors('treatment_plan_item_id');

        $this->assertSame(1, AuditLog::where('action', AuditAction::TreatmentPlanDeclined->value)->count());
        $this->assertSame(1, AuditLog::where('action', AuditAction::TreatmentPlanClosed->value)->count());
    }

    public function test_sessions_are_used_by_treatments_without_a_new_charge_and_return_when_voided(): void
    {
        $plan = $this->createPlan([['procedure_id' => $this->microneedling->id, 'sessions' => '2', 'unit_price' => '400']]);
        $this->approve($plan);
        $item = $plan->items()->first();
        $chargesBefore = FinancialEntry::count();

        $this->get("/pacientes/{$this->patient->id}/atendimentos/novo")->assertOk()->assertSee('Sessão de pacote')->assertSee('restam 2 de 2');

        $this->post("/pacientes/{$this->patient->id}/atendimentos", $this->treatment($item))->assertSessionHasNoErrors();
        $treatment = Treatment::query()->latest('id')->firstOrFail();
        $this->assertSame($item->id, $treatment->treatment_plan_item_id);
        $this->assertSame($chargesBefore, FinancialEntry::count());
        $this->assertSame(1, $this->remaining($item));

        // Procedimento diferente do pacote é recusado.
        $this->post("/pacientes/{$this->patient->id}/atendimentos", $this->treatment($item, ['procedure_id' => $this->peeling->id]))->assertSessionHasErrors('treatment_plan_item_id');

        $this->post("/pacientes/{$this->patient->id}/atendimentos", $this->treatment($item))->assertSessionHasNoErrors();
        $this->assertSame(0, $this->remaining($item));
        $this->post("/pacientes/{$this->patient->id}/atendimentos", $this->treatment($item))->assertSessionHasErrors('treatment_plan_item_id');
        $this->assertSame(2, Treatment::count());

        // Anular devolve a sessão.
        $this->actingAs(User::factory()->admin()->create())
            ->post("/pacientes/{$this->patient->id}/atendimentos/{$treatment->id}/anulacao", ['reason' => 'Registrado errado.'])
            ->assertSessionHasNoErrors();
        $this->assertSame(1, $this->remaining($item));
        $this->assertSame($chargesBefore, FinancialEntry::count());
    }

    public function test_a_session_of_another_patient_cannot_be_used(): void
    {
        $plan = $this->createPlan();
        $this->approve($plan);
        $item = $plan->items()->first();
        $other = Patient::factory()->create();

        $this->post("/pacientes/{$other->id}/atendimentos", $this->treatment($item))->assertSessionHasErrors('treatment_plan_item_id');

        // Pelo diálogo, com o paciente escolhido no formulário, também.
        $this->post('/atendimentos', [...$this->treatment($item), 'patient_id' => $other->id])->assertSessionHasErrors('treatment_plan_item_id');
        $this->assertSame(0, Treatment::count());
    }

    public function test_dialog_lists_the_available_sessions_of_the_chosen_patient(): void
    {
        $plan = $this->createPlan();
        $this->approve($plan);

        $this->getJson("/pacientes/{$this->patient->id}/sessoes-de-pacote")
            ->assertOk()
            ->assertJsonCount(2, 'sessions')
            ->assertJsonPath('sessions.0.label', "Microagulhamento · pacote nº {$plan->id} · restam 4 de 4");

        $this->getJson('/pacientes/'.Patient::factory()->create()->id.'/sessoes-de-pacote')->assertOk()->assertJsonCount(0, 'sessions');

        $this->actingAs(User::factory()->create())->getJson("/pacientes/{$this->patient->id}/sessoes-de-pacote")->assertForbidden();
    }

    public function test_access_follows_the_plan_permissions(): void
    {
        $plan = $this->createPlan();

        // A recepção vende pacotes por padrão.
        $reception = User::factory()->create();
        $this->actingAs($reception)->get('/orcamentos')->assertOk()->assertSee('Mariana Costa');
        $this->get($this->plansUrl()."/{$plan->id}")->assertOk();

        DB::table('role_permissions')->where('role', 'receptionist')->where('permission', 'like', 'plans.%')->delete();
        app(RolePermissions::class)->flush();

        $this->get('/orcamentos')->assertForbidden();
        $this->get($this->plansUrl().'/novo')->assertForbidden();
        $this->get($this->plansUrl()."/{$plan->id}")->assertForbidden();
        $this->get($this->plansUrl()."/{$plan->id}/imprimir")->assertForbidden();
        $this->approve($plan)->assertForbidden();
        $this->post($this->plansUrl(), ['items' => [['procedure_id' => $this->peeling->id, 'sessions' => '1', 'unit_price' => '1']]])->assertForbidden();
        $this->get("/pacientes/{$this->patient->id}")->assertDontSee('Novo orçamento');

        // O orçamento só é alcançado pelo próprio paciente.
        $this->actingAs($this->professional)->get($this->plansUrl(Patient::factory()->create())."/{$plan->id}")->assertNotFound();
    }

    public function test_printed_quote_has_the_clinic_header_and_is_audited(): void
    {
        ClinicProfile::query()->forceCreate(['id' => ClinicProfile::SINGLETON_ID, 'legal_name' => 'Clínica Bella Ltda.']);
        $plan = $this->createPlan();

        $this->get($this->plansUrl()."/{$plan->id}/imprimir")
            ->assertOk()
            ->assertSee('Clínica Bella Ltda.')
            ->assertSee('Microagulhamento')
            ->assertSee('R$ 1.980,00')
            ->assertSee('Intervalo de 30 dias');

        $this->assertSame(1, AuditLog::where('action', AuditAction::TreatmentPlanPrinted->value)->count());
    }

    public function test_plans_list_keeps_a_constant_number_of_queries(): void
    {
        $this->createPlan();
        $this->actingAs(User::factory()->create());

        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->get('/orcamentos')->assertOk();

            return count(DB::getQueryLog());
        };

        $count();
        $before = $count();

        foreach (range(1, 4) as $index) {
            $patient = Patient::factory()->create();
            $plan = new TreatmentPlan(['valid_until' => '2026-10-30']);
            $plan->patient_id = $patient->id;
            $plan->status = TreatmentPlanStatus::Open;
            $plan->save();
            $plan->items()->create(['procedure_id' => $this->procedure("Procedimento {$index}")->id, 'sessions' => 2, 'unit_price' => '100']);
        }

        $this->assertSame($before, $count());
    }
}
