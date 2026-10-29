<?php

namespace Tests\Feature;

use App\Enums\AuditAction;
use App\Enums\PaymentMethod;
use App\Enums\TreatmentPlanStatus;
use App\Models\AuditLog;
use App\Models\Patient;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\Treatment;
use App\Models\TreatmentPlan;
use App\Models\User;
use App\Services\StockLedger;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Margem por procedimento: receita dos atendimentos menos o custo dos produtos baixados.
 */
class ProcedureMarginTest extends TestCase
{
    use RefreshDatabase;

    private User $professional;

    private Patient $patient;

    private ProductBatch $toxin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-08 13:00', 'America/Sao_Paulo'));
        $this->professional = User::factory()->professional()->create();
        $this->patient = Patient::factory()->create();
        $this->toxin = ProductBatch::factory()->create([
            'product_id' => Product::factory()->create(['name' => 'Toxina 100 U'])->id, 'batch_code' => 'T1', 'quantity' => 0, 'expires_on' => '2027-06-30',
        ]);
        // Custo de R$ 10,00 por unidade, gravado na entrada.
        app(StockLedger::class)->enter($this->toxin, '100', 'Compra', null, ['unit_cost' => '10.0000']);
    }

    private function record(int $procedureId, string $amount, string $units, array $extra = []): Treatment
    {
        $this->actingAs($this->professional)->post("/pacientes/{$this->patient->id}/atendimentos", [
            'performed_at' => '2026-10-08T12:00',
            'procedure_id' => $procedureId,
            'description' => 'Aplicação.',
            'products' => [['product_batch_id' => $this->toxin->id, 'quantity' => $units]],
            'amount' => $amount,
            'payment_method' => PaymentMethod::Pix->value,
            'installments' => 1,
            'first_due_date' => '2026-10-08',
            ...$extra,
        ])->assertSessionHasNoErrors();

        return Treatment::query()->latest('id')->firstOrFail();
    }

    public function test_margin_adds_charges_and_package_sessions_and_subtracts_product_cost(): void
    {
        $toxinProcedure = $this->procedure('Toxina botulínica', ['price' => 1200]);
        $evaluation = $this->procedure('Avaliação', ['price' => 0]);

        // Cobrado à parte: R$ 1.200 de receita, 30 U a R$ 10 de custo.
        $this->record($toxinProcedure->id, '1.200,00', '30');

        // Sessão de pacote: a receita é o valor da sessão no pacote (R$ 1.000), 10 U de custo.
        $plan = new TreatmentPlan;
        $plan->patient_id = $this->patient->id;
        $plan->status = TreatmentPlanStatus::Approved;
        $plan->save();
        $item = $plan->items()->create(['procedure_id' => $toxinProcedure->id, 'sessions' => 2, 'unit_price' => '1000']);
        $this->record($toxinProcedure->id, '0', '10', ['treatment_plan_item_id' => $item->id]);

        // Anulado não conta.
        $voided = $this->record($toxinProcedure->id, '1.200,00', '20');
        $this->actingAs(User::factory()->admin()->create())->post("/pacientes/{$this->patient->id}/atendimentos/{$voided->id}/anulacao", ['reason' => 'Registrado com a dose errada.'])->assertSessionHasNoErrors();

        $this->record($evaluation->id, '150,00', '1');

        $rows = $this->actingAs(User::factory()->admin()->create())
            ->get('/financeiro/margem?de=2026-10-01&ate=2026-10-31')
            ->assertOk()
            ->assertSee('Receitas e margem')
            ->viewData('rows')
            ->keyBy('name');

        $this->assertSame(2, $rows['Toxina botulínica']->treatments);
        $this->assertEqualsWithDelta(2200.0, $rows['Toxina botulínica']->revenue, 0.001);
        $this->assertEqualsWithDelta(400.0, $rows['Toxina botulínica']->cost, 0.001);
        $this->assertSame(0, $rows['Toxina botulínica']->uncosted);
        $this->assertEqualsWithDelta(150.0, $rows['Avaliação']->revenue, 0.001);
        $this->assertEqualsWithDelta(10.0, $rows['Avaliação']->cost, 0.001);

        $this->assertSame(1, AuditLog::where('action', AuditAction::FinanceMarginViewed->value)->count());

        // Fora do período, nada.
        $this->get('/financeiro/margem?de=2026-09-01&ate=2026-09-30')->assertOk()->assertSee('Nenhum atendimento registrado no período');
    }

    public function test_only_who_sees_the_finance_opens_the_margin(): void
    {
        $this->actingAs($this->professional)->get('/financeiro/margem')->assertForbidden();
        $this->actingAs(User::factory()->create())->get('/financeiro/margem')->assertForbidden();
        $this->actingAs(User::factory()->admin()->create())->get('/financeiro/margem?profissional=abc')->assertSessionHasErrors('profissional');
    }
}
