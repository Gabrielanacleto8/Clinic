<?php

namespace Tests\Feature;

use App\Enums\AuditAction;
use App\Enums\FinancialCategory;
use App\Models\AuditLog;
use App\Models\FinancialEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Receita parcelada no cartão de crédito: a maquininha repassa o valor
 * inteiro, então as parcelas são recebidas (e desfeitas) todas juntas.
 */
class CreditCardInstallmentsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-08 13:00', 'America/Sao_Paulo'));
        $this->admin = User::factory()->admin()->create();
    }

    private function launch(string $type, string $method, bool $paid): FinancialEntry
    {
        $this->actingAs($this->admin)->post('/financeiro/lancamentos', [
            'type' => $type,
            'category' => ($type === 'income' ? FinancialCategory::OtherIncome : FinancialCategory::Rent)->value,
            'description' => "Lançamento {$type} {$method}",
            'issued_on' => '2026-10-05',
            'total' => '900,00',
            'payment_method' => $method,
            'installments' => 3,
            'first_due_date' => '2026-10-10',
            'first_installment_paid' => $paid ? '1' : '0',
        ])->assertSessionHasNoErrors();

        return FinancialEntry::query()->latest('id')->firstOrFail();
    }

    /**
     * @return list<string|null>
     */
    private function paidDates(FinancialEntry $entry): array
    {
        return $entry->installments()->orderBy('number')->get()->map(fn ($installment) => $installment->paid_at?->format('Y-m-d'))->all();
    }

    public function test_credit_card_income_informed_as_paid_has_every_installment_received(): void
    {
        $entry = $this->launch('income', 'credit_card', paid: true);

        $this->assertSame(['2026-10-05', '2026-10-05', '2026-10-05'], $this->paidDates($entry));
    }

    public function test_credit_card_expense_keeps_paying_month_by_month(): void
    {
        // A fatura do cartão da clínica é paga mês a mês.
        $entry = $this->launch('expense', 'credit_card', paid: true);

        $this->assertSame(['2026-10-05', null, null], $this->paidDates($entry));
    }

    public function test_receiving_one_open_credit_card_installment_receives_all_of_them(): void
    {
        $entry = $this->launch('income', 'credit_card', paid: false);
        $this->assertSame([null, null, null], $this->paidDates($entry));

        $second = $entry->installments()->where('number', 2)->sole();

        $this->post('/financeiro/pagamentos', ['installment_id' => $second->id, 'paid_at' => '2026-10-07'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Pagamento registrado nas 3 parcelas do cartão de crédito.');

        $this->assertSame(['2026-10-07', '2026-10-07', '2026-10-07'], $this->paidDates($entry));
        $this->assertSame(3, AuditLog::where('action', AuditAction::InstallmentPaid->value)->count());

        // Desfazer também vale para o lançamento inteiro.
        $this->delete("/financeiro/pagamentos/{$second->id}")
            ->assertSessionHas('success', 'Pagamento desfeito. As 3 parcelas do cartão de crédito voltaram a ficar em aberto.');

        $this->assertSame([null, null, null], $this->paidDates($entry));
        $this->assertSame(3, AuditLog::where('action', AuditAction::InstallmentReopened->value)->count());
    }

    public function test_other_payment_methods_still_settle_one_installment_at_a_time(): void
    {
        $entry = $this->launch('income', 'bank_slip', paid: true);
        $third = $entry->installments()->where('number', 3)->sole();

        $this->post('/financeiro/pagamentos', ['installment_id' => $third->id, 'paid_at' => '2026-10-08'])
            ->assertSessionHas('success', 'Pagamento registrado.');
        $this->assertSame(['2026-10-05', null, '2026-10-08'], $this->paidDates($entry));

        $this->delete("/financeiro/pagamentos/{$third->id}")
            ->assertSessionHas('success', 'Pagamento desfeito. A parcela voltou a ficar em aberto.');
        $this->assertSame(['2026-10-05', null, null], $this->paidDates($entry));
    }

    public function test_settling_still_requires_the_finance_permission(): void
    {
        $entry = $this->launch('income', 'credit_card', paid: false);
        $first = $entry->installments()->where('number', 1)->sole();

        $this->actingAs(User::factory()->professional()->create())
            ->post('/financeiro/pagamentos', ['installment_id' => $first->id, 'paid_at' => '2026-10-08'])
            ->assertForbidden();

        $this->assertSame([null, null, null], $this->paidDates($entry));
    }

    public function test_undoing_still_requires_the_finance_permission(): void
    {
        $entry = $this->launch('income', 'credit_card', paid: true);
        $first = $entry->installments()->where('number', 1)->sole();

        $this->actingAs(User::factory()->professional()->create())->delete("/financeiro/pagamentos/{$first->id}")->assertForbidden();

        auth()->logout();
        $this->delete("/financeiro/pagamentos/{$first->id}")->assertRedirect('/login');

        $this->assertSame(['2026-10-05', '2026-10-05', '2026-10-05'], $this->paidDates($entry));
    }
}
