<?php

namespace Tests\Feature;

use App\Enums\AuditAction;
use App\Enums\PaymentMethod;
use App\Models\AuditLog;
use App\Models\CashClosing;
use App\Models\FinancialEntry;
use App\Models\Patient;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Caixa do dia: esperado por forma de pagamento, fechamento com conferência
 * e a trava que impede mexer em pagamento de dia fechado.
 */
class CashClosingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-08 13:00', 'America/Sao_Paulo'));
        $this->admin = User::factory()->admin()->create();
    }

    private function entry(string $amount, ?string $paidAt, PaymentMethod $method = PaymentMethod::Cash, bool $expense = false, ?Patient $patient = null): FinancialEntry
    {
        $factory = FinancialEntry::factory()->withInstallment($amount, '2026-10-08', $paidAt)->state(['payment_method' => $method, 'patient_id' => $patient?->id]);

        return ($expense ? $factory->expense() : $factory)->create(['description' => $expense ? 'Material de limpeza' : 'Atendimento: Toxina']);
    }

    public function test_the_day_shows_what_came_in_and_out_by_payment_method(): void
    {
        $patient = Patient::factory()->create(['name' => 'Joana Prado']);
        $this->entry('300', '2026-10-08', PaymentMethod::Cash, patient: $patient);
        $this->entry('500', '2026-10-08', PaymentMethod::Pix);
        $this->entry('40', '2026-10-08', PaymentMethod::Cash, expense: true);
        $this->entry('999', '2026-10-07', PaymentMethod::Cash);
        $this->entry('999', null, PaymentMethod::Cash);

        $response = $this->actingAs($this->admin)->get('/financeiro/caixa')->assertOk()
            ->assertSee('Joana Prado')
            ->assertSee('Material de limpeza')
            ->assertSee('Fechar o caixa do dia')
            // Dia anterior com pagamento e sem fechamento.
            ->assertSee('Dias com pagamento e sem fechamento');

        $this->assertEquals(['cash' => ['in' => 300.0, 'out' => 40.0], 'pix' => ['in' => 500.0, 'out' => 0.0]], $response->viewData('movement'));
        $this->assertSame(1, AuditLog::where('action', AuditAction::FinanceViewed->value)->where('metadata->view', 'cash')->count());
    }

    public function test_closing_records_expected_and_counted_and_locks_the_day(): void
    {
        $this->entry('300', '2026-10-08', PaymentMethod::Cash);
        $this->entry('500', '2026-10-08', PaymentMethod::Pix);
        $open = $this->entry('200', null, PaymentMethod::Cash);

        $this->actingAs($this->admin)
            ->post('/financeiro/caixa', ['day' => '2026-10-08', 'counted' => ['cash' => '290,00', 'pix' => '500,00'], 'notes' => 'Faltou troco.'])
            ->assertRedirect('/financeiro/caixa?dia=2026-10-08')
            ->assertSessionHas('success', 'Caixa de 08/10/2026 fechado. Falta de R$ 10,00 registrada.');

        $closing = CashClosing::query()->with('lines')->sole();
        $this->assertSame($this->admin->id, $closing->closed_by);
        $this->assertEquals(['cash' => ['300.00', '290.00'], 'pix' => ['500.00', '500.00']], $closing->lines->mapWithKeys(fn ($line) => [$line->payment_method->value => [$line->expected, $line->counted]])->all());
        $this->assertSame(-10.0, $closing->difference());
        $this->assertSame(1, AuditLog::where('action', AuditAction::CashClosed->value)->count());

        // Dia fechado: não recebe pagamento novo nem desfeito, nem outro fechamento.
        $this->post('/financeiro/pagamentos', ['installment_id' => $open->installments()->first()->id, 'paid_at' => '2026-10-08'])
            ->assertSessionHasErrors(['paid_at' => 'O caixa de 08/10/2026 já foi fechado. Use outra data ou peça ao administrador para reabrir o dia.']);
        $this->assertNull($open->installments()->first()->paid_at);

        $paid = FinancialEntry::query()->where('payment_method', 'pix')->sole()->installments()->first();
        $this->delete("/financeiro/pagamentos/{$paid->id}")->assertSessionHasErrors('installment_id');
        $this->assertNotNull($paid->refresh()->paid_at);

        $this->post('/financeiro/caixa', ['day' => '2026-10-08', 'counted' => ['cash' => '0']])->assertSessionHasErrors('day');

        // Outro dia continua aberto.
        $this->post('/financeiro/pagamentos', ['installment_id' => $open->installments()->first()->id, 'paid_at' => '2026-10-07'])->assertSessionHasNoErrors();

        $this->get('/financeiro/caixa?dia=2026-10-08')->assertOk()->assertSee('Caixa fechado')->assertSee('Faltou troco.')->assertSee('Reabrir o dia');
    }

    public function test_a_manual_entry_already_paid_on_a_closed_day_is_refused(): void
    {
        $this->actingAs($this->admin)->post('/financeiro/caixa', ['day' => '2026-10-08', 'counted' => ['cash' => '0']])->assertSessionHasNoErrors();

        $this->post('/financeiro/lancamentos', [
            'type' => 'income', 'category' => 'other_income', 'description' => 'Venda de produto', 'issued_on' => '2026-10-08',
            'total' => '100,00', 'payment_method' => 'cash', 'installments' => 1, 'first_due_date' => '2026-10-08', 'first_installment_paid' => '1',
        ])->assertSessionHasErrors('first_installment_paid');

        $this->assertSame(0, FinancialEntry::query()->count());
    }

    public function test_every_method_with_movement_must_be_counted(): void
    {
        $this->entry('500', '2026-10-08', PaymentMethod::Pix);

        $this->actingAs($this->admin)->post('/financeiro/caixa', ['day' => '2026-10-08', 'counted' => ['cash' => '0']])
            ->assertSessionHasErrors('counted.pix');
        $this->post('/financeiro/caixa', ['day' => '2026-10-09', 'counted' => ['cash' => '0']])->assertSessionHasErrors('day');
        $this->post('/financeiro/caixa', ['day' => '2026-10-08', 'counted' => ['cash' => 'abc', 'pix' => '1']])->assertSessionHasErrors('counted.cash');
        $this->post('/financeiro/caixa', ['day' => '2026-10-08', 'counted' => ['outra' => '1']])->assertSessionHasErrors('counted');

        $this->assertSame(0, CashClosing::query()->count());
    }

    public function test_only_the_admin_reopens_a_day(): void
    {
        $receptionist = User::factory()->create();
        $this->actingAs($receptionist)->post('/financeiro/caixa', ['day' => '2026-10-08', 'counted' => ['cash' => '0']])->assertSessionHasNoErrors();
        $closing = CashClosing::query()->sole();

        $this->delete("/financeiro/caixa/{$closing->id}")->assertForbidden();
        $this->get('/financeiro/caixa')->assertDontSee('Reabrir o dia');

        $this->actingAs($this->admin)->delete("/financeiro/caixa/{$closing->id}")->assertRedirect('/financeiro/caixa?dia=2026-10-08');
        $this->assertSame(0, CashClosing::query()->count());
        $this->assertSame(1, AuditLog::where('action', AuditAction::CashReopened->value)->count());
    }

    public function test_the_receptionist_closes_the_cash_without_seeing_the_whole_finance(): void
    {
        $receptionist = User::factory()->create();
        $this->entry('300', '2026-10-08', PaymentMethod::Cash);
        $this->entry('2500', '2026-10-08', PaymentMethod::BankTransfer, expense: true);

        $this->actingAs($receptionist)->get('/')->assertSee('Caixa do dia')->assertSee(route('finance.cash.show'), false);
        $this->get('/financeiro')->assertForbidden();

        // Vê o total que saiu na forma, mas não a descrição da despesa.
        $this->get('/financeiro/caixa')->assertOk()->assertSee('Recebimentos do dia')->assertSee('Atendimento: Toxina')->assertDontSee('Material de limpeza');

        // O profissional não fecha nem vê o caixa.
        $professional = User::factory()->professional()->create();
        $this->actingAs($professional)->get('/financeiro/caixa')->assertForbidden();
        $this->post('/financeiro/caixa', ['day' => '2026-10-08', 'counted' => ['cash' => '0']])->assertForbidden();
        $this->get('/')->assertDontSee('Caixa do dia');

        // Quem vê o financeiro tem a aba, não o item de menu separado.
        $this->actingAs($this->admin)->get('/')->assertDontSee('Caixa do dia');
    }

    public function test_the_day_runs_a_fixed_number_of_queries(): void
    {
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->get('/financeiro/caixa')->assertOk();

            return count(DB::getQueryLog());
        };

        $this->actingAs($this->admin);
        $this->entry('10', '2026-10-08', patient: Patient::factory()->create());
        $few = $count();

        foreach (range(1, 8) as $index) {
            $this->entry('10', '2026-10-08', PaymentMethod::Pix, patient: Patient::factory()->create());
        }

        $this->assertSame($few, $count());
    }
}
