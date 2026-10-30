<?php

namespace Tests\Feature;

use App\Enums\FinancialCategory;
use App\Enums\Permission;
use App\Enums\UserRole;
use App\Models\FinancialEntry;
use App\Models\Patient;
use App\Models\User;
use App\Services\RolePermissions;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinanceEntryDialogTest extends TestCase
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
    private function expense(array $overrides = []): array
    {
        return [
            '_dialog' => 'entry-expense',
            'type' => 'expense',
            'category' => FinancialCategory::Rent->value,
            'description' => 'Aluguel de outubro',
            'issued_on' => '2026-10-05',
            'total' => '2.500,00',
            'payment_method' => 'pix',
            'installments' => 1,
            'first_due_date' => '2026-10-10',
            'first_installment_paid' => '0',
            ...$overrides,
        ];
    }

    public function test_new_expense_and_income_open_in_dialogs_on_the_overview_and_payables(): void
    {
        $this->actingAs($this->admin);

        $this->get('/financeiro')->assertOk()
            ->assertSee('data-dialog-open="entry-expense"', false)->assertSee('data-dialog-open="entry-income"', false)
            ->assertSee('<dialog id="entry-expense"', false)->assertSee('<dialog id="entry-income"', false)
            ->assertSee('id="entry-expense-description"', false)->assertSee('id="entry-income-description"', false);

        $this->get('/financeiro/a-pagar')->assertOk()
            ->assertSee('data-dialog-open="entry-expense"', false)->assertSee('<dialog id="entry-expense"', false)
            ->assertDontSee('<dialog id="entry-income"', false);
        $this->get('/financeiro/a-receber')->assertOk()->assertDontSee('<dialog id="entry-expense"', false);
    }

    public function test_saving_from_the_dialog_returns_to_the_screen_it_was_opened_from(): void
    {
        $this->actingAs($this->admin)->from('/financeiro/a-pagar')
            ->post('/financeiro/lancamentos', $this->expense())
            ->assertRedirect('/financeiro/a-pagar')->assertSessionHas('success', 'Despesa lançada.');

        $this->assertSame(1, FinancialEntry::count());

        // Pela página de lançamento, continua indo para a visão geral do mês.
        $this->from('/financeiro/lancamentos/novo')->post('/financeiro/lancamentos', [...$this->expense(), '_dialog' => null])
            ->assertRedirect('/financeiro?mes=2026-10');
    }

    public function test_dialog_reopens_with_the_typed_data_and_errors(): void
    {
        $html = $this->actingAs($this->admin)->from('/financeiro')->followingRedirects()
            ->post('/financeiro/lancamentos', $this->expense(['total' => '', 'first_installment_paid' => '1']))
            ->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<dialog id="entry-expense"[^>]*data-dialog-autoshow/', $html);
        $this->assertDoesNotMatchRegularExpression('/<dialog id="entry-income"[^>]*data-dialog-autoshow/', $html);
        $this->assertMatchesRegularExpression('/id="entry-expense-description"[^>]*value="Aluguel de outubro"/s', $html);
        $this->assertDoesNotMatchRegularExpression('/id="entry-income-description"[^>]*value="Aluguel de outubro"/s', $html);
        $this->assertStringContainsString('id="entry-expense-total-error"', $html);
        $this->assertMatchesRegularExpression('/name="first_installment_paid" value="1"[^>]*checked/', $html);
        $this->assertSame(0, FinancialEntry::count());
    }

    public function test_income_dialog_keeps_the_chosen_patient_after_an_error(): void
    {
        $patient = Patient::factory()->create(['name' => 'Paciente Escolhida']);

        $this->actingAs($this->admin)->from('/financeiro')->post('/financeiro/lancamentos', [
            ...$this->expense(['_dialog' => 'entry-income', 'type' => 'income', 'category' => FinancialCategory::OtherIncome->value, 'total' => '']),
            'patient_id' => $patient->id,
        ])->assertSessionHasErrors('total');

        $this->get('/financeiro')->assertSee('value="'.$patient->id.'" data-patient-id', false)->assertSee('value="Paciente Escolhida"', false);
    }

    public function test_dialogs_follow_the_permissions(): void
    {
        app(RolePermissions::class)->sync(UserRole::Receptionist, [Permission::FinanceView]);
        $reader = User::factory()->create();

        $this->actingAs($reader)->get('/financeiro')->assertOk()->assertDontSee('<dialog id="entry-expense"', false)->assertDontSee('Nova despesa');
        $this->get('/financeiro/a-pagar')->assertOk()->assertDontSee('<dialog id="entry-expense"', false);
        $this->post('/financeiro/lancamentos', $this->expense())->assertForbidden();

        $this->assertSame(0, FinancialEntry::count());
    }
}
