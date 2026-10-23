<?php

namespace Tests\Feature;

use App\Enums\FinancialCategory;
use App\Enums\PaymentMethod;
use App\Models\FinancialInstallment;
use App\Models\Patient;
use App\Models\User;
use App\Services\FinancialRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Quem só recebe de paciente não alcança outras parcelas do financeiro,
 * nem por um envio montado à mão que volta com erro.
 */
class ReceiveScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_receiver_cannot_read_or_pay_an_expense_through_the_payment_dialog(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-08 13:00', 'America/Sao_Paulo'));
        $admin = User::factory()->admin()->create();
        $patient = Patient::factory()->create();

        $expense = app(FinancialRecorder::class)->record(
            category: FinancialCategory::Payroll, description: 'Salario SECRETO da Dra X', total: '9876', paymentMethod: PaymentMethod::Pix,
            installments: 1, firstDueDate: '2026-10-10', firstInstallmentPaid: false, createdBy: $admin,
        );
        $installment = $expense->installments()->sole();

        $receptionist = User::factory()->create();

        // Com data inválida, antes a validação devolvia o diálogo com o resumo da parcela.
        $this->actingAs($receptionist)
            ->from("/pacientes/{$patient->id}?aba=financeiro")
            ->post('/financeiro/pagamentos', ['_dialog' => 'installment-payment', 'installment_id' => $installment->id, 'paid_at' => '2099-01-01'])
            ->assertForbidden();

        $this->get("/pacientes/{$patient->id}?aba=financeiro")->assertOk()
            ->assertDontSee('Salario SECRETO')->assertDontSee('9.876,00');

        $this->post('/financeiro/pagamentos', ['installment_id' => $installment->id, 'paid_at' => '2026-10-08'])->assertForbidden();
        $this->assertNull(FinancialInstallment::find($installment->id)->paid_at);
    }

    public function test_receiver_still_receives_a_patient_charge(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-08 13:00', 'America/Sao_Paulo'));
        $patient = Patient::factory()->create();
        $income = app(FinancialRecorder::class)->record(
            category: FinancialCategory::Treatment, description: 'Atendimento', total: '300', paymentMethod: PaymentMethod::Pix,
            installments: 1, firstDueDate: '2026-10-08', firstInstallmentPaid: false, createdBy: null, patient: $patient,
        );

        $this->actingAs(User::factory()->create())
            ->post('/financeiro/pagamentos', ['installment_id' => $income->installments()->sole()->id, 'paid_at' => '2026-10-08'])
            ->assertSessionHasNoErrors();

        $this->assertNotNull($income->installments()->sole()->paid_at);
    }
}
