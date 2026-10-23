<?php

namespace Tests\Feature;

use App\Enums\AuditAction;
use App\Enums\FinancialCategory;
use App\Enums\PaymentMethod;
use App\Enums\ProcedureCategory;
use App\Models\AuditLog;
use App\Models\FinancialEntry;
use App\Models\Patient;
use App\Models\Procedure;
use App\Models\Product;
use App\Models\User;
use App\Services\FinancialRecorder;
use App\Support\InstallmentPlan;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinanceAndCatalogRulesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-08 13:00', 'America/Sao_Paulo'));
    }

    public function test_manual_entry_installments_need_at_least_one_cent(): void
    {
        $this->actingAs(User::factory()->admin()->create())->post('/financeiro/lancamentos', [
            'type' => 'expense',
            'category' => FinancialCategory::Rent->value,
            'description' => 'Taxa',
            'issued_on' => '2026-10-05',
            'total' => '0,05',
            'payment_method' => 'pix',
            'installments' => 12,
            'first_due_date' => '2026-10-10',
            'first_installment_paid' => '0',
        ])->assertSessionHasErrors(['installments' => InstallmentPlan::TOO_MANY_MESSAGE]);

        $this->assertSame(0, FinancialEntry::count());
    }

    public function test_open_filter_lists_overdue_and_upcoming_and_archived_patients_keep_their_name(): void
    {
        $admin = User::factory()->admin()->create();
        $patient = Patient::factory()->create(['name' => 'Paciente Arquivada']);
        $recorder = app(FinancialRecorder::class);

        $recorder->record(category: FinancialCategory::Treatment, description: 'Vencida', total: '100', paymentMethod: PaymentMethod::Pix,
            installments: 1, firstDueDate: '2026-10-02', firstInstallmentPaid: false, createdBy: $admin, issuedOn: '2026-10-01', patient: $patient);
        $recorder->record(category: FinancialCategory::Treatment, description: 'A vencer', total: '100', paymentMethod: PaymentMethod::Pix,
            installments: 1, firstDueDate: '2026-10-20', firstInstallmentPaid: false, createdBy: $admin, issuedOn: '2026-10-01');
        $patient->delete();

        $this->actingAs($admin);

        $this->get('/financeiro?mes=2026-10&situacao=abertas')->assertOk()
            ->assertSee('Vencida')->assertSee('A vencer')->assertSee('Paciente Arquivada');
        $this->get('/financeiro?mes=2026-10&situacao=pendentes')->assertOk()
            ->assertSee('A vencer')->assertDontSee('Vencida</');
    }

    public function test_saving_a_procedure_keeps_typical_products_that_became_inactive(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $inactive = Product::factory()->create(['name' => 'Fio antigo', 'is_active' => false]);
        $active = Product::factory()->create(['name' => 'Agulha 30G']);
        $procedure = Procedure::factory()->create(['name' => 'Fios', 'category' => ProcedureCategory::Injectable]);
        $procedure->products()->sync([$inactive->id => ['quantity' => 2], $active->id => ['quantity' => 1000]]);

        $form = $this->get("/procedimentos/{$procedure->id}/editar")->assertOk()
            ->assertSee('Fio antigo')->assertSee('inativo')
            // Quantidade de mil sem separador, para não voltar como 1 ao salvar.
            ->assertSee('value="1000"', false);

        $this->put("/procedimentos/{$procedure->id}", [
            'name' => 'Fios',
            'category' => ProcedureCategory::Injectable->value,
            'price' => '900,00',
            'duration_minutes' => 60,
            'is_active' => '1',
            'products' => [
                ['product_id' => $inactive->id, 'quantity' => '2'],
                ['product_id' => $active->id, 'quantity' => '1000'],
            ],
        ])->assertSessionHasNoErrors();

        $this->assertEqualsCanonicalizing([$inactive->id, $active->id], $procedure->products()->pluck('products.id')->all());
        $this->assertSame('1000.000', $procedure->products()->whereKey($active->id)->first()->pivot->quantity);
    }

    public function test_payment_and_its_reversal_are_audited_with_the_patient(): void
    {
        $admin = User::factory()->admin()->create();
        $patient = Patient::factory()->create(['name' => 'Paciente Pagante']);
        $recorder = app(FinancialRecorder::class);

        $entry = $recorder->record(category: FinancialCategory::Treatment, description: 'Sessão', total: '300', paymentMethod: PaymentMethod::Pix,
            installments: 1, firstDueDate: '2026-10-08', firstInstallmentPaid: false, createdBy: $admin, issuedOn: '2026-10-08', patient: $patient);
        $expense = $recorder->record(category: FinancialCategory::Rent, description: 'Aluguel', total: '100', paymentMethod: PaymentMethod::Pix,
            installments: 1, firstDueDate: '2026-10-08', firstInstallmentPaid: false, createdBy: $admin, issuedOn: '2026-10-08');
        $installment = $entry->installments()->sole();

        $this->actingAs($admin);
        $this->post('/financeiro/pagamentos', ['installment_id' => $installment->id, 'paid_at' => '2026-10-08'])->assertSessionHasNoErrors();
        $this->delete("/financeiro/pagamentos/{$installment->id}")->assertSessionHasNoErrors();
        $this->post('/financeiro/pagamentos', ['installment_id' => $expense->installments()->sole()->id, 'paid_at' => '2026-10-08'])->assertSessionHasNoErrors();

        // Valor e data do pagamento ficam na trilha: são do lançamento, não dados pessoais.
        $expected = ['financial_entry_id' => $entry->id, 'patient_id' => $patient->id, 'amount' => '300.00', 'paid_at' => '2026-10-08'];
        $this->assertEquals($expected, AuditLog::where('action', AuditAction::InstallmentPaid->value)->where('auditable_id', $installment->id)->sole()->metadata);
        $this->assertEquals($expected, AuditLog::where('action', AuditAction::InstallmentReopened->value)->sole()->metadata);
        // Lançamento sem paciente não leva a chave.
        $this->assertEquals(['financial_entry_id' => $expense->id, 'amount' => $expense->installments()->sole()->amount, 'paid_at' => '2026-10-08'], AuditLog::where('action', AuditAction::InstallmentPaid->value)->latest('id')->first()->metadata);
        $this->assertStringNotContainsString('Paciente Pagante', (string) json_encode(AuditLog::all()->pluck('metadata')));

        // A trilha do paciente reúne o pagamento e o estorno.
        $actions = $this->get("/auditoria?paciente={$patient->id}")->assertOk()->viewData('logs')->getCollection()->map(fn (AuditLog $log) => $log->action)->all();
        $this->assertContains(AuditAction::InstallmentPaid, $actions);
        $this->assertContains(AuditAction::InstallmentReopened, $actions);
    }
}
