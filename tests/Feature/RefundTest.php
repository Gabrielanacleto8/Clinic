<?php

namespace Tests\Feature;

use App\Enums\AuditAction;
use App\Enums\FinancialCategory;
use App\Enums\FinancialEntryType;
use App\Models\AuditLog;
use App\Models\FinancialEntry;
use App\Models\Patient;
use App\Models\User;
use App\Services\CashRegister;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Estorno: devolução ao paciente de parte do que ele pagou numa cobrança.
 */
class RefundTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Patient $patient;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-08 13:00', 'America/Sao_Paulo'));
        $this->admin = User::factory()->admin()->create();
        $this->patient = Patient::factory()->create(['name' => 'Joana Prado']);
    }

    private function charge(string $amount = '600', ?string $paidAt = '2026-10-05'): FinancialEntry
    {
        return FinancialEntry::factory()->withInstallment($amount, '2026-10-05', $paidAt)
            ->create(['patient_id' => $this->patient->id, 'description' => 'Atendimento: Bioestimulador']);
    }

    private function refund(FinancialEntry $entry, array $overrides = []): TestResponse
    {
        return $this->post('/financeiro/estornos', [
            'entry_id' => $entry->id, 'amount' => '200,00', 'refunded_on' => '2026-10-08', 'payment_method' => 'pix', 'reason' => 'dissatisfaction', ...$overrides,
        ]);
    }

    public function test_a_paid_charge_is_refunded_in_parts_up_to_what_was_paid(): void
    {
        $charge = $this->charge();

        $this->actingAs($this->admin)->get('/financeiro?mes=2026-10')->assertSee('data-dialog-open="refund"', false);

        $this->refund($charge)->assertSessionHasNoErrors()->assertSessionHas('success');

        $refund = FinancialEntry::query()->where('refund_of_id', $charge->id)->sole();
        $this->assertSame(FinancialEntryType::Expense, $refund->type);
        $this->assertSame(FinancialCategory::Refund, $refund->category);
        $this->assertSame($this->patient->id, $refund->patient_id);
        $this->assertSame('Estorno (insatisfação com o resultado): Atendimento: Bioestimulador', $refund->description);
        $this->assertSame('200.00', $refund->total());
        $this->assertSame('2026-10-08', $refund->installments()->first()->paid_at->format('Y-m-d'));
        $this->assertTrue($refund->isGenerated());

        $log = AuditLog::where('action', AuditAction::RefundRecorded->value)->sole();
        $this->assertSame($charge->id, $log->metadata['refund_of_id']);
        $this->assertSame('dissatisfaction', $log->metadata['reason']);

        // Na ficha do paciente, o pago já vem sem o estornado e o estorno sai com sinal de menos.
        $tab = $this->get("/pacientes/{$this->patient->id}?aba=financeiro")->assertOk()->assertSee('− R$ 200,00');
        $this->assertEqualsWithDelta(400.0, (float) $tab->viewData('financialTotals')->paid, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $tab->viewData('financialTotals')->pending, 0.001);

        // Só o que falta: 400.
        $this->refund($charge, ['amount' => '400,01'])->assertSessionHasErrors(['amount' => 'O estorno passa do que o paciente pagou e ainda não foi devolvido (R$ 400,00).']);
        $this->refund($charge, ['amount' => '400,00'])->assertSessionHasNoErrors();
        $this->refund($charge, ['amount' => '1'])->assertSessionHasErrors(['amount' => 'Não há valor pago a devolver nesta cobrança.']);

        // O estorno sai no caixa do dia em que foi devolvido.
        $this->get('/financeiro/caixa')->assertOk();
        $this->assertSame(['in' => 0.0, 'out' => 600.0], app(CashRegister::class)->movement('2026-10-08')['pix']);
    }

    public function test_refund_rules(): void
    {
        $this->actingAs($this->admin);

        // Sem pagamento, nada a devolver.
        $this->refund($this->charge('300', null))->assertSessionHasErrors('amount');

        // Receita sem paciente e despesa não são estornadas.
        $income = FinancialEntry::factory()->withInstallment('100', '2026-10-05', '2026-10-05')->create();
        $this->refund($income)->assertForbidden();
        $expense = FinancialEntry::factory()->expense()->withInstallment('100', '2026-10-05', '2026-10-05')->create();
        $this->refund($expense)->assertForbidden();

        $charge = $this->charge();
        $this->refund($charge, ['amount' => '0'])->assertSessionHasErrors('amount');
        $this->refund($charge, ['refunded_on' => '2026-10-09'])->assertSessionHasErrors('refunded_on');
        $this->refund($charge, ['reason' => 'texto livre'])->assertSessionHasErrors('reason');
        $this->refund($charge, ['entry_id' => 999999])->assertSessionHasErrors('entry_id');

        // Dia fechado não recebe estorno.
        $this->post('/financeiro/caixa', ['day' => '2026-10-08', 'counted' => ['cash' => '0']])->assertSessionHasNoErrors();
        $this->refund($charge)->assertSessionHasErrors('first_installment_paid');
        $this->assertSame(0, FinancialEntry::query()->whereNotNull('refund_of_id')->count());
    }

    public function test_undoing_a_refund_and_its_effect_on_undoing_the_payment(): void
    {
        $charge = $this->charge();
        $this->actingAs($this->admin)->refund($charge)->assertSessionHasNoErrors();
        $refund = FinancialEntry::query()->where('refund_of_id', $charge->id)->sole();

        // Com estorno, o pagamento da cobrança não é desfeito.
        $this->delete('/financeiro/pagamentos/'.$charge->installments()->first()->id)->assertSessionHasErrors('installment_id');
        // O estorno não sai pela exclusão comum.
        $this->delete("/financeiro/lancamentos/{$refund->id}")->assertForbidden();

        $this->get('/financeiro?mes=2026-10')->assertSee('Desfazer estorno');
        $this->delete("/financeiro/estornos/{$refund->id}")->assertSessionHasNoErrors();
        $this->assertModelMissing($refund);
        $this->assertSame(1, AuditLog::where('action', AuditAction::RefundUndone->value)->count());

        $this->delete("/financeiro/estornos/{$charge->id}")->assertNotFound();
        $this->delete('/financeiro/pagamentos/'.$charge->installments()->first()->id)->assertSessionHasNoErrors();
    }

    public function test_only_who_manages_the_finance_refunds(): void
    {
        $charge = $this->charge();
        $this->actingAs($this->admin)->refund($charge)->assertSessionHasNoErrors();
        $refund = FinancialEntry::query()->where('refund_of_id', $charge->id)->sole();

        foreach ([User::factory()->create(), User::factory()->professional()->create()] as $user) {
            $this->actingAs($user)->refund($charge)->assertForbidden();
            $this->delete("/financeiro/estornos/{$refund->id}")->assertForbidden();
        }

        $this->assertSame(1, FinancialEntry::query()->whereNotNull('refund_of_id')->count());
    }
}
