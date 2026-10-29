<?php

namespace Tests\Feature;

use App\Enums\AuditAction;
use App\Enums\PaymentMethod;
use App\Models\AuditLog;
use App\Models\FinancialEntry;
use App\Models\FinancialInstallment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Conciliação: recebimentos fora do dinheiro conferidos no extrato, com a taxa.
 */
class ReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-08 13:00', 'America/Sao_Paulo'));
        $this->admin = User::factory()->admin()->create();
    }

    private function received(string $amount, PaymentMethod $method, ?string $paidAt = '2026-10-05', string $description = 'Atendimento: Toxina'): FinancialInstallment
    {
        return FinancialEntry::factory()->withInstallment($amount, '2026-10-05', $paidAt)
            ->state(['payment_method' => $method])->create(['description' => $description])->installments()->first();
    }

    public function test_received_payments_are_reconciled_with_the_fee(): void
    {
        $card = $this->received('1000', PaymentMethod::CreditCard, description: 'Cartão da Ana');
        $pix = $this->received('300', PaymentMethod::Pix, description: 'Pix do Bruno');
        $this->received('200', PaymentMethod::Cash, description: 'Dinheiro do Caio');
        $this->received('400', PaymentMethod::Pix, null, 'Em aberto da Duda');

        $this->actingAs($this->admin)->get('/financeiro/conciliacao')->assertOk()
            ->assertSee('Cartão da Ana')->assertSee('Pix do Bruno')
            ->assertDontSee('Dinheiro do Caio')->assertDontSee('Em aberto da Duda');

        $this->post('/financeiro/conciliacao', [
            'installments' => [$card->id, $pix->id],
            'fees' => [$card->id => '34,90', $pix->id => ''],
            'reconciled_on' => '2026-10-07',
        ])->assertSessionHasNoErrors()->assertSessionHas('success', '2 recebimentos conciliados.');

        $card->refresh();
        $this->assertSame('2026-10-07', $card->reconciled_on->format('Y-m-d'));
        $this->assertSame('34.90', $card->fee);
        $this->assertSame($this->admin->id, $card->reconciled_by);
        $this->assertNull($pix->refresh()->fee);
        $this->assertSame(2, AuditLog::where('action', AuditAction::InstallmentReconciled->value)->count());

        // Já conferido não entra de novo.
        $this->post('/financeiro/conciliacao', ['installments' => [$card->id], 'reconciled_on' => '2026-10-07'])->assertSessionHasErrors('installments');

        $this->get('/financeiro/conciliacao?situacao=conferidos')->assertSee('Conferido em 07/10/2026')->assertSee('R$ 34,90');

        // A taxa sai no fluxo de caixa do dia em que caiu.
        $flow = $this->get('/financeiro/fluxo-de-caixa?mes=2026-10')->assertOk();
        $this->assertEqualsWithDelta(34.90, $flow->viewData('totals')->fees, 0.001);

        $this->delete("/financeiro/conciliacao/{$card->id}")->assertSessionHasNoErrors();
        $this->assertNull($card->refresh()->reconciled_on);
        $this->assertNull($card->fee);
        $this->assertSame(1, AuditLog::where('action', AuditAction::InstallmentUnreconciled->value)->count());
        $this->delete("/financeiro/conciliacao/{$card->id}")->assertNotFound();
    }

    public function test_reconciliation_rules(): void
    {
        $card = $this->received('1000', PaymentMethod::CreditCard);
        $cash = $this->received('200', PaymentMethod::Cash);
        $open = $this->received('400', PaymentMethod::Pix, null);

        $this->actingAs($this->admin);
        $this->post('/financeiro/conciliacao', ['installments' => [$cash->id], 'reconciled_on' => '2026-10-07'])->assertSessionHasErrors('installments');
        $this->post('/financeiro/conciliacao', ['installments' => [$open->id], 'reconciled_on' => '2026-10-07'])->assertSessionHasErrors('installments');
        $this->post('/financeiro/conciliacao', ['installments' => [$card->id], 'fees' => [$card->id => '1000'], 'reconciled_on' => '2026-10-07'])->assertSessionHasErrors("fees.{$card->id}");
        $this->post('/financeiro/conciliacao', ['installments' => [$card->id], 'reconciled_on' => '2026-10-04'])->assertSessionHasErrors('reconciled_on');
        $this->post('/financeiro/conciliacao', ['installments' => [$card->id], 'reconciled_on' => '2026-10-09'])->assertSessionHasErrors('reconciled_on');
        $this->post('/financeiro/conciliacao', ['reconciled_on' => '2026-10-07'])->assertSessionHasErrors('installments');
        $this->get('/financeiro/conciliacao?forma=cash')->assertSessionHasErrors('forma');

        $this->assertNull($card->refresh()->reconciled_on);
    }

    public function test_only_who_manages_the_finance_reconciles(): void
    {
        $card = $this->received('1000', PaymentMethod::CreditCard);

        foreach ([User::factory()->create(), User::factory()->professional()->create()] as $user) {
            $this->actingAs($user)->get('/financeiro/conciliacao')->assertForbidden();
            $this->post('/financeiro/conciliacao', ['installments' => [$card->id], 'reconciled_on' => '2026-10-07'])->assertForbidden();
        }

        $this->assertNull($card->refresh()->reconciled_on);
    }
}
