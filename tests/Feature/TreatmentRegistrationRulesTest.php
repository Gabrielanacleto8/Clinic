<?php

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Enums\PaymentMethod;
use App\Enums\Permission;
use App\Enums\ProductUnit;
use App\Enums\UserRole;
use App\Models\Appointment;
use App\Models\FinancialEntry;
use App\Models\Patient;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\StockUnit;
use App\Models\Treatment;
use App\Models\User;
use App\Services\RolePermissions;
use App\Support\InstallmentPlan;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regras do registro de atendimento que protegem a agenda, o financeiro e o
 * estoque de erros comuns no dia a dia.
 */
class TreatmentRegistrationRulesTest extends TestCase
{
    use RefreshDatabase;

    private User $professional;

    private Patient $patient;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-08 13:00', 'America/Sao_Paulo'));

        $this->professional = User::factory()->professional()->create(['name' => 'Dra. Helena']);
        $this->patient = Patient::factory()->create(['name' => 'Marina Albuquerque']);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'performed_at' => '2026-10-08T12:00',
            'procedure_id' => $this->procedure('Toxina botulínica', ['price' => 1200])->id,
            'description' => 'Aplicados 30 U.',
            'amount' => '1.200,00',
            'payment_method' => PaymentMethod::Pix->value,
            'installments' => 1,
            'first_due_date' => '2026-10-08',
            'first_installment_paid' => '1',
            ...$overrides,
        ];
    }

    public function test_the_dialog_closes_the_patients_open_appointment_of_that_day(): void
    {
        $today = Appointment::factory()->at('2026-10-08 11:00')->create(['patient_id' => $this->patient->id, 'professional_id' => $this->professional->id]);
        // Agendamento de outro dia, ainda em aberto, não é tocado.
        $earlier = Appointment::factory()->at('2026-10-01 11:00')->create(['patient_id' => $this->patient->id, 'professional_id' => $this->professional->id]);

        $this->actingAs($this->professional)
            ->post('/atendimentos', $this->payload(['patient_id' => $this->patient->id]))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', fn (string $message) => str_contains($message, 'passou para "Atendido"'));

        $treatment = Treatment::sole();
        $this->assertSame([AppointmentStatus::Completed, $treatment->id], [$today->refresh()->status, $today->treatment_id]);
        $this->assertSame([AppointmentStatus::Scheduled, null], [$earlier->refresh()->status, $earlier->treatment_id]);

        // O mesmo agendamento não recebe um segundo registro pelo diálogo.
        $this->post('/atendimentos', $this->payload(['patient_id' => $this->patient->id]))->assertSessionHasNoErrors();
        $this->assertSame($treatment->id, $today->refresh()->treatment_id);
        $this->assertNull(Treatment::query()->latest('id')->first()->appointment);
    }

    public function test_the_dialog_without_appointment_records_normally(): void
    {
        $this->actingAs($this->professional)
            ->post('/atendimentos', $this->payload(['patient_id' => $this->patient->id]))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Atendimento registrado. Estoque e financeiro foram atualizados.');

        $this->assertSame(1, Treatment::count());
    }

    public function test_admin_must_choose_the_professional_and_an_inactive_one_is_not_preselected(): void
    {
        $admin = User::factory()->admin()->create();
        $former = User::factory()->professional()->inactive()->create(['name' => 'Dr. Desligado']);
        $appointment = Appointment::factory()->at('2026-10-08 11:00')->create(['patient_id' => $this->patient->id, 'professional_id' => $former->id]);

        $this->actingAs($admin)
            ->get("/pacientes/{$this->patient->id}/atendimentos/novo?agendamento={$appointment->id}")
            ->assertOk()
            ->assertSee('<option value="">Selecione</option>', false)
            ->assertDontSee('Dr. Desligado');

        $this->post("/pacientes/{$this->patient->id}/atendimentos", $this->payload(['appointment_id' => $appointment->id]))
            ->assertSessionHasErrors(['professional_id' => 'Escolha o profissional responsável.']);
        $this->assertSame(0, Treatment::count());

        $this->post("/pacientes/{$this->patient->id}/atendimentos", $this->payload(['appointment_id' => $appointment->id, 'professional_id' => $this->professional->id]))
            ->assertSessionHasNoErrors();
        $this->assertSame($this->professional->id, Treatment::sole()->professional_id);
    }

    public function test_only_who_can_register_payments_marks_the_charge_as_received(): void
    {
        $url = "/pacientes/{$this->patient->id}/atendimentos";

        // Profissional sem permissão no financeiro: a caixa nem aparece e, enviada, é ignorada.
        $this->actingAs($this->professional)->get("{$url}/novo")->assertOk()
            ->assertDontSee('name="first_installment_paid" value="1"', false)
            ->assertSee('A cobrança entra como pendente.');
        $this->post($url, $this->payload())->assertSessionHasNoErrors();
        $this->assertNull(FinancialEntry::sole()->installments()->sole()->paid_at);

        app(RolePermissions::class)->sync(UserRole::Professional, [...Permission::defaultsFor(UserRole::Professional), Permission::FinanceManage]);

        $this->actingAs($this->professional->fresh())->get("{$url}/novo")->assertSee('name="first_installment_paid" value="1"', false);
        $this->post($url, $this->payload())->assertSessionHasNoErrors();
        $this->assertNotNull(FinancialEntry::query()->latest('id')->first()->installments()->sole()->paid_at);
    }

    public function test_installments_cannot_be_smaller_than_one_cent(): void
    {
        $this->actingAs($this->professional)
            ->post("/pacientes/{$this->patient->id}/atendimentos", $this->payload(['amount' => '0,05', 'installments' => 12]))
            ->assertSessionHasErrors(['installments' => InstallmentPlan::TOO_MANY_MESSAGE]);

        $this->assertSame(0, Treatment::count());
    }

    public function test_two_new_single_use_units_of_the_same_batch_can_be_used_in_one_treatment(): void
    {
        $filler = Product::factory()->create([
            'name' => 'Ácido hialurônico', 'unit' => ProductUnit::Milliliter,
            'unit_content' => 1, 'hours_after_opening' => null, 'multi_patient_use' => false,
        ]);
        $batch = ProductBatch::factory()->create(['product_id' => $filler->id, 'batch_code' => 'AH01', 'quantity' => 3, 'expires_on' => '2027-06-30']);

        $this->actingAs($this->professional)
            ->post("/pacientes/{$this->patient->id}/atendimentos", $this->payload([
                'products' => [
                    ['product_batch_id' => $batch->id, 'quantity' => '1'],
                    ['product_batch_id' => $batch->id, 'quantity' => '0,8'],
                ],
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame(2, StockUnit::where('product_batch_id', $batch->id)->count());
        $this->assertSame('1.000', $batch->refresh()->quantity);
    }

    public function test_a_row_larger_than_one_new_unit_explains_how_to_split_it(): void
    {
        $filler = Product::factory()->create(['unit' => ProductUnit::Milliliter, 'unit_content' => 1, 'multi_patient_use' => false]);
        $batch = ProductBatch::factory()->create(['product_id' => $filler->id, 'quantity' => 3, 'expires_on' => '2027-06-30']);

        $this->actingAs($this->professional)
            ->post("/pacientes/{$this->patient->id}/atendimentos", $this->payload([
                'products' => [['product_batch_id' => $batch->id, 'quantity' => '2']],
            ]))
            ->assertSessionHasErrors('products');

        $this->assertStringContainsString('Use uma linha para cada unidade aberta.', session('errors')->first('products'));

        $this->assertSame(0, StockUnit::count());
    }

    public function test_a_plain_batch_still_cannot_repeat(): void
    {
        $needle = ProductBatch::factory()->create(['quantity' => 50, 'expires_on' => null]);

        $this->actingAs($this->professional)
            ->post("/pacientes/{$this->patient->id}/atendimentos", $this->payload([
                'products' => [['product_batch_id' => $needle->id, 'quantity' => '1'], ['product_batch_id' => $needle->id, 'quantity' => '1']],
            ]))
            ->assertSessionHasErrors('products.1.product_batch_id');
    }
}
