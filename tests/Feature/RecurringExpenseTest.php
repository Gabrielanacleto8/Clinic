<?php

namespace Tests\Feature;

use App\Enums\AuditAction;
use App\Enums\CostBehavior;
use App\Enums\FinancialCategory;
use App\Models\AuditLog;
use App\Models\FinancialEntry;
use App\Models\RecurringExpense;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Despesas fixas: recorrências lançadas uma vez por mês e o resumo fixas × variáveis.
 */
class RecurringExpenseTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-08 13:00', 'America/Sao_Paulo'));
        $this->admin = User::factory()->admin()->create();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'category' => FinancialCategory::Rent->value,
            'description' => 'Aluguel da sala',
            'amount' => '3.500,00',
            'payment_method' => 'bank_transfer',
            'due_day' => '10',
            'starts_on' => '2026-01-01',
            'ends_on' => '',
            'is_active' => '1',
            ...$overrides,
        ];
    }

    public function test_a_recurring_expense_is_generated_once_a_month_from_now_on(): void
    {
        $this->actingAs($this->admin)->post('/financeiro/despesas-fixas', $this->payload())
            ->assertRedirect('/financeiro/despesas-fixas')
            ->assertSessionHas('success', 'Despesa recorrente cadastrada. A deste mês já está em Contas a pagar.');

        // Começou em janeiro, mas só o mês do cadastro é lançado: nada retroativo.
        $entry = FinancialEntry::query()->sole();
        $this->assertSame(FinancialCategory::Rent, $entry->category);
        $this->assertSame('3500.00', $entry->total());
        $this->assertSame('2026-10-10', $entry->installments()->first()->due_date->format('Y-m-d'));
        $this->assertNull($entry->installments()->first()->paid_at);
        $this->assertFalse($entry->isGenerated());
        $this->assertSame(1, AuditLog::where('action', AuditAction::RecurringExpenseCreated->value)->count());

        // O agendador não repete o mês, nem depois que a despesa gerada é excluída.
        $this->artisan('clinic:recurring-expenses')->assertSuccessful();
        $this->assertSame(1, FinancialEntry::query()->count());
        $this->delete("/financeiro/lancamentos/{$entry->id}")->assertSessionHasNoErrors();
        $this->artisan('clinic:recurring-expenses')->assertSuccessful();
        $this->assertSame(0, FinancialEntry::query()->count());

        // No mês seguinte, lança o novo mês.
        $this->travelTo(CarbonImmutable::parse('2026-11-01 08:00', 'America/Sao_Paulo'));
        $this->artisan('clinic:recurring-expenses')->assertSuccessful();
        $this->assertSame('2026-11-10', FinancialEntry::query()->sole()->installments()->first()->due_date->format('Y-m-d'));
        $this->assertSame('2026-11-01', RecurringExpense::query()->sole()->generated_until->format('Y-m-d'));

        $this->get('/financeiro/a-pagar')->assertSee('Aluguel da sala');
    }

    public function test_future_start_end_and_inactive_are_respected(): void
    {
        $this->actingAs($this->admin)->post('/financeiro/despesas-fixas', $this->payload(['starts_on' => '2026-12-01', 'description' => 'Contador']))
            ->assertSessionHas('success', 'Despesa recorrente cadastrada. Ela será lançada no mês do início.');
        $this->assertSame(0, FinancialEntry::query()->count());

        $this->post('/financeiro/despesas-fixas', $this->payload(['starts_on' => '2026-08-01', 'ends_on' => '2026-09-30', 'description' => 'Já acabou']));
        $this->assertSame(0, FinancialEntry::query()->count());

        $expense = RecurringExpense::query()->where('description', 'Contador')->sole();
        $this->put("/financeiro/despesas-fixas/{$expense->id}", $this->payload(['starts_on' => '2026-12-01', 'description' => 'Contador', 'is_active' => '0', 'amount' => '900']))
            ->assertSessionHasNoErrors();
        $this->assertFalse($expense->refresh()->is_active);
        $this->assertSame('900.00', $expense->amount);
        $this->assertSame(1, AuditLog::where('action', AuditAction::RecurringExpenseUpdated->value)->count());

        $this->travelTo(CarbonImmutable::parse('2026-12-02 08:00', 'America/Sao_Paulo'));
        $this->artisan('clinic:recurring-expenses')->assertSuccessful();
        $this->assertSame(0, FinancialEntry::query()->count());
    }

    public function test_validation_and_access(): void
    {
        $this->actingAs($this->admin);
        $this->post('/financeiro/despesas-fixas', $this->payload(['due_day' => '31']))->assertSessionHasErrors('due_day');
        $this->post('/financeiro/despesas-fixas', $this->payload(['category' => 'treatment']))->assertSessionHasErrors('category');
        $this->post('/financeiro/despesas-fixas', $this->payload(['category' => 'refund']))->assertSessionHasErrors('category');
        $this->post('/financeiro/despesas-fixas', $this->payload(['ends_on' => '2025-12-31']))->assertSessionHasErrors('ends_on');
        $this->post('/financeiro/despesas-fixas', $this->payload(['amount' => '0']))->assertSessionHasErrors('amount');
        $this->assertSame(0, RecurringExpense::query()->count());

        foreach ([User::factory()->create(), User::factory()->professional()->create()] as $user) {
            $this->actingAs($user)->post('/financeiro/despesas-fixas', $this->payload())->assertForbidden();
        }

        $this->assertSame(0, RecurringExpense::query()->count());
    }

    public function test_expenses_of_the_period_are_split_between_fixed_and_variable(): void
    {
        FinancialEntry::factory()->expense(FinancialCategory::Rent)->withInstallment('3000', '2026-10-10')->create(['issued_on' => '2026-10-10']);
        FinancialEntry::factory()->expense(FinancialCategory::Supplies)->withInstallment('800', '2026-10-12')->create(['issued_on' => '2026-10-12']);
        FinancialEntry::factory()->expense(FinancialCategory::Payroll)->withInstallment('5000', '2026-09-05')->create(['issued_on' => '2026-09-05']);
        FinancialEntry::factory()->withInstallment('1000', '2026-10-01', '2026-10-01')->state(['payment_method' => 'credit_card'])->create()
            ->installments()->update(['reconciled_on' => '2026-10-03', 'fee' => '35.00']);

        $response = $this->actingAs($this->admin)->get('/financeiro/despesas-fixas?mes=2026-10')->assertOk()
            ->assertSee('Aluguel e condomínio')->assertSee('Taxas descontadas na conciliação');

        $rows = collect($response->viewData('byCategory'))->keyBy(fn ($row) => $row['category']->value);
        $this->assertSame(CostBehavior::Fixed, $rows['rent']['behavior']);
        $this->assertSame(CostBehavior::Variable, $rows['supplies']['behavior']);
        $this->assertArrayNotHasKey('payroll', $rows->all());
        $this->assertEqualsWithDelta(35.0, $response->viewData('cardFees'), 0.001);
    }
}
