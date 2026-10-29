<?php

namespace Tests\Feature;

use App\Enums\AuditAction;
use App\Enums\FinancialCategory;
use App\Enums\PaymentMethod;
use App\Enums\TreatmentPlanStatus;
use App\Models\AuditLog;
use App\Models\CommissionPayout;
use App\Models\FinancialEntry;
use App\Models\Patient;
use App\Models\Procedure;
use App\Models\Treatment;
use App\Models\TreatmentPlan;
use App\Models\User;
use App\Services\CommissionLedger;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Comissões: regras, base no que foi pago, repasse e acerto.
 */
class CommissionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $professional;

    private Patient $patient;

    private Procedure $toxin;

    private Procedure $peeling;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-08 13:00', 'America/Sao_Paulo'));
        $this->admin = User::factory()->admin()->create();
        $this->professional = User::factory()->professional()->create(['name' => 'Dra. Helena']);
        $this->patient = Patient::factory()->create();
        $this->toxin = $this->procedure('Toxina botulínica', ['price' => 1000]);
        $this->peeling = $this->procedure('Peeling químico', ['price' => 400]);
    }

    private function rules(array $defaults, array $exceptions = []): void
    {
        $this->actingAs($this->admin)->put('/financeiro/comissoes/regras', ['defaults' => $defaults, 'exceptions' => $exceptions])->assertSessionHasNoErrors();
    }

    private function record(Procedure $procedure, string $amount, array $extra = []): Treatment
    {
        $this->actingAs($this->admin)->post("/pacientes/{$this->patient->id}/atendimentos", [
            'performed_at' => '2026-10-08T12:00',
            'procedure_id' => $procedure->id,
            'professional_id' => $this->professional->id,
            'description' => 'Aplicação.',
            'amount' => $amount,
            'payment_method' => PaymentMethod::Pix->value,
            'installments' => 1,
            'first_due_date' => '2026-10-08',
            ...$extra,
        ])->assertSessionHasNoErrors();

        return Treatment::query()->latest('id')->firstOrFail();
    }

    private function pay(FinancialEntry $entry, string $date = '2026-10-08'): void
    {
        foreach ($entry->installments()->whereNull('paid_at')->get() as $installment) {
            $this->actingAs($this->admin)->post('/financeiro/pagamentos', ['installment_id' => $installment->id, 'paid_at' => $date])->assertSessionHasNoErrors();
        }
    }

    private function pending(): float
    {
        return round(app(CommissionLedger::class)->rows('2026-10-08', $this->professional->id)->sum('pending'), 2);
    }

    public function test_commission_follows_the_rules_and_what_the_patient_paid(): void
    {
        $this->rules([$this->professional->id => '30'], [['user_id' => $this->professional->id, 'procedure_id' => $this->peeling->id, 'rate' => '0']]);
        $this->assertSame(1, AuditLog::where('action', AuditAction::CommissionRatesUpdated->value)->count());

        $treatment = $this->record($this->toxin, '1.000,00');
        $this->assertSame('30.00', $treatment->commission_rate);
        $peeling = $this->record($this->peeling, '400,00');
        $this->assertSame('0.00', $peeling->commission_rate);

        // Nada pago, nada devido.
        $this->assertSame(0.0, $this->pending());

        $charge = FinancialEntry::query()->where('treatment_id', $treatment->id)->sole();
        $this->pay($charge);
        $this->pay(FinancialEntry::query()->where('treatment_id', $peeling->id)->sole());
        $this->assertSame(300.0, $this->pending());

        // Estorno reduz a base.
        $this->post('/financeiro/estornos', ['entry_id' => $charge->id, 'amount' => '200', 'refunded_on' => '2026-10-08', 'payment_method' => 'pix', 'reason' => 'withdrawal'])->assertSessionHasNoErrors();
        $this->assertSame(240.0, $this->pending());

        // Mudar a regra não muda o atendimento já registrado.
        $this->rules([$this->professional->id => '50']);
        $this->assertSame(240.0, $this->pending());

        $this->get('/financeiro/comissoes')->assertOk()->assertSee('Dra. Helena')->assertSee('R$ 240,00');
        $this->get("/financeiro/comissoes/{$this->professional->id}")->assertOk()->assertSee('Registrar repasse de R$ 240,00');
    }

    public function test_package_session_counts_in_proportion_to_the_package_paid(): void
    {
        $this->rules([$this->professional->id => '20']);

        $plan = new TreatmentPlan;
        $plan->patient_id = $this->patient->id;
        $plan->status = TreatmentPlanStatus::Open;
        $plan->save();
        $item = $plan->items()->create(['procedure_id' => $this->toxin->id, 'sessions' => 2, 'unit_price' => '500', 'list_price' => '500']);
        $this->actingAs($this->admin)->post("/pacientes/{$this->patient->id}/orcamentos/{$plan->id}/aprovacao", [
            'payment_method' => PaymentMethod::Pix->value, 'installments' => '2', 'first_due_date' => '2026-10-08',
        ])->assertSessionHasNoErrors();

        $this->record($this->toxin, '0', ['treatment_plan_item_id' => $item->id]);
        $this->assertSame(0.0, $this->pending());

        // Metade do pacote paga: a sessão de R$ 500 vale R$ 250 de base.
        $first = FinancialEntry::query()->where('treatment_plan_id', $plan->id)->sole()->installments()->orderBy('number')->first();
        $this->post('/financeiro/pagamentos', ['installment_id' => $first->id, 'paid_at' => '2026-10-08'])->assertSessionHasNoErrors();
        $this->assertSame(50.0, $this->pending());
    }

    public function test_a_payout_pays_what_is_pending_and_voiding_later_is_settled_next_time(): void
    {
        $this->rules([$this->professional->id => '10']);

        $plan = new TreatmentPlan;
        $plan->patient_id = $this->patient->id;
        $plan->status = TreatmentPlanStatus::Open;
        $plan->save();
        $item = $plan->items()->create(['procedure_id' => $this->toxin->id, 'sessions' => 2, 'unit_price' => '1000', 'list_price' => '1000']);
        $this->actingAs($this->admin)->post("/pacientes/{$this->patient->id}/orcamentos/{$plan->id}/aprovacao", [
            'payment_method' => PaymentMethod::Pix->value, 'installments' => '1', 'first_due_date' => '2026-10-08', 'first_installment_paid' => '1',
        ])->assertSessionHasNoErrors();
        $session = $this->record($this->toxin, '0', ['treatment_plan_item_id' => $item->id]);
        $this->assertSame(100.0, $this->pending());

        $this->post("/financeiro/comissoes/{$this->professional->id}/repasses", ['until' => '2026-10-08', 'payment_method' => 'pix', 'paid_now' => '0'])
            ->assertRedirect("/financeiro/comissoes/{$this->professional->id}?ate=2026-10-08");

        $payout = CommissionPayout::query()->with(['items', 'entry'])->sole();
        $this->assertSame('100.00', $payout->items->sole()->amount);
        $this->assertSame(FinancialCategory::Commissions, $payout->entry->category);
        $this->assertSame('100.00', $payout->entry->total());
        $this->assertTrue($payout->entry->isGenerated());
        $this->assertSame(0.0, $this->pending());
        $this->assertSame(1, AuditLog::where('action', AuditAction::CommissionPaid->value)->count());

        // Nada mais a repassar.
        $this->post("/financeiro/comissoes/{$this->professional->id}/repasses", ['until' => '2026-10-08', 'payment_method' => 'pix'])->assertSessionHasErrors('until');

        // Excluído enquanto não pago, a comissão volta.
        $this->delete("/financeiro/repasses/{$payout->id}")->assertSessionHasNoErrors();
        $this->assertSame(100.0, $this->pending());
        $this->assertSame(0, FinancialEntry::query()->where('category', FinancialCategory::Commissions->value)->count());

        // Pago na hora, não se exclui.
        $this->post("/financeiro/comissoes/{$this->professional->id}/repasses", ['until' => '2026-10-08', 'payment_method' => 'pix', 'paid_now' => '1'])->assertSessionHasNoErrors();
        $paid = CommissionPayout::query()->sole();
        $this->assertTrue($paid->entry->hasPaidInstallment());
        $this->delete("/financeiro/repasses/{$paid->id}")->assertSessionHasErrors('payout');

        // Sessão anulada depois do repasse: saldo negativo, acertado no próximo.
        $this->post("/pacientes/{$this->patient->id}/atendimentos/{$session->id}/anulacao", ['reason' => 'Registrado no paciente errado.'])->assertSessionHasNoErrors();
        $this->assertSame(-100.0, $this->pending());
        $this->get("/financeiro/comissoes/{$this->professional->id}")->assertSee('Saldo negativo de R$ 100,00');
    }

    public function test_rules_validation(): void
    {
        $this->actingAs($this->admin);
        $this->put('/financeiro/comissoes/regras', ['defaults' => [$this->professional->id => '101']])->assertSessionHasErrors("defaults.{$this->professional->id}");
        $this->put('/financeiro/comissoes/regras', ['defaults' => ['999999' => '10']])->assertSessionHasErrors('defaults');
        $this->put('/financeiro/comissoes/regras', ['exceptions' => [
            ['user_id' => $this->professional->id, 'procedure_id' => $this->toxin->id, 'rate' => '10'],
            ['user_id' => $this->professional->id, 'procedure_id' => $this->toxin->id, 'rate' => '20'],
        ]])->assertSessionHasErrors('exceptions');
        $this->put('/financeiro/comissoes/regras', ['exceptions' => [['user_id' => $this->professional->id, 'procedure_id' => '', 'rate' => '10']]])->assertSessionHasErrors('exceptions.0.procedure_id');

        $this->assertSame(0, DB::table('commission_rates')->count());
        $this->get('/financeiro/comissoes/regras')->assertOk()->assertSee('Dra. Helena');
    }

    public function test_only_who_manages_the_finance_changes_rules_and_pays(): void
    {
        $viewer = User::factory()->create();
        $professional = $this->professional;

        foreach ([$viewer, $professional] as $user) {
            $this->actingAs($user);
            $this->get('/financeiro/comissoes')->assertForbidden();
            $this->get("/financeiro/comissoes/{$professional->id}")->assertForbidden();
            $this->get('/financeiro/comissoes/regras')->assertForbidden();
            $this->put('/financeiro/comissoes/regras', ['defaults' => [$professional->id => '50']])->assertForbidden();
            $this->post("/financeiro/comissoes/{$professional->id}/repasses", ['until' => '2026-10-08', 'payment_method' => 'pix'])->assertForbidden();
        }

        $this->assertSame(0, DB::table('commission_rates')->count());
    }

    public function test_revenue_by_professional_shows_discount_and_commission(): void
    {
        $this->rules([$this->professional->id => '10']);
        $treatment = $this->record($this->toxin, '900,00');
        $this->pay(FinancialEntry::query()->where('treatment_id', $treatment->id)->sole());

        $rows = $this->actingAs($this->admin)->get('/financeiro/margem?mes=2026-10&agrupar=profissional')->assertOk()
            ->assertSee('Dra. Helena')
            ->viewData('rows')->keyBy('name');

        $this->assertEqualsWithDelta(900.0, $rows['Dra. Helena']->revenue, 0.001);
        $this->assertEqualsWithDelta(100.0, $rows['Dra. Helena']->discount, 0.001);
        $this->assertEqualsWithDelta(90.0, $rows['Dra. Helena']->commission, 0.001);

        $this->get('/financeiro/margem?agrupar=outro')->assertSessionHasErrors('agrupar');
    }
}
