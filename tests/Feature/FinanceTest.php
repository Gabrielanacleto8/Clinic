<?php

namespace Tests\Feature;

use App\Enums\AuditAction;
use App\Enums\FinancialCategory;
use App\Enums\FinancialEntryType;
use App\Enums\Permission;
use App\Enums\UserRole;
use App\Models\FinancialEntry;
use App\Models\FinancialInstallment;
use App\Models\Treatment;
use App\Models\User;
use App\Services\RolePermissions;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinanceTest extends TestCase
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

    private function entry(string $amount, string $dueDate, ?string $paidAt = null, string $issuedOn = '2026-10-01', bool $expense = false, string $description = 'Lançamento'): FinancialEntry
    {
        $factory = FinancialEntry::factory()->withInstallment($amount, $dueDate, $paidAt);

        return ($expense ? $factory->expense() : $factory)->create(['issued_on' => $issuedOn, 'description' => $description]);
    }

    public function test_access_follows_the_role_permissions(): void
    {
        $entry = $this->entry('100', '2026-10-10');
        $installment = $entry->installments()->first();

        $this->get('/financeiro')->assertRedirect('/login');

        // Por padrão só o administrador vê o financeiro.
        foreach ([User::factory()->create(), User::factory()->professional()->create()] as $user) {
            $this->actingAs($user);
            $this->get('/financeiro')->assertForbidden();
            $this->get('/financeiro/lancamentos/novo')->assertForbidden();
            $this->post('/financeiro/lancamentos', $this->payload())->assertForbidden();
            $this->post('/financeiro/pagamentos', ['installment_id' => $installment->id, 'paid_at' => '2026-10-08'])->assertForbidden();
            $this->delete("/financeiro/lancamentos/{$entry->id}")->assertForbidden();
            $this->get('/')->assertDontSee(route('finance.index'), false);
        }

        // Perfil com permissão só de leitura: vê, mas não lança nem dá baixa.
        app(RolePermissions::class)->sync(UserRole::Receptionist, [Permission::FinanceView]);

        $this->actingAs(User::factory()->create());
        $this->get('/financeiro')->assertOk()->assertSee('Lançamento')->assertDontSee('Nova despesa')->assertDontSee('data-dialog-open', false);
        $this->post('/financeiro/lancamentos', $this->payload())->assertForbidden();
        $this->post('/financeiro/pagamentos', ['installment_id' => $installment->id, 'paid_at' => '2026-10-08'])->assertForbidden();

        $this->assertSame(1, FinancialEntry::count());
        $this->assertNull($installment->refresh()->paid_at);
    }

    public function test_admin_can_register_an_expense_paid_in_cash(): void
    {
        $this->actingAs($this->admin);
        $this->get('/financeiro/lancamentos/novo')->assertOk()->assertSee('Nova despesa')->assertSee('Aluguel e condomínio')->assertDontSee('Outras receitas');

        $this->post('/financeiro/lancamentos', $this->payload(['first_installment_paid' => '1']))
            ->assertRedirect(route('finance.index', ['mes' => '2026-10']))->assertSessionHasNoErrors();

        $entry = FinancialEntry::with('installments')->sole();

        // O tipo decorre da categoria.
        $this->assertSame(FinancialEntryType::Expense, $entry->type);
        $this->assertSame('2026-10-05', $entry->issued_on->format('Y-m-d'));
        $this->assertSame($this->admin->id, $entry->created_by);
        $this->assertCount(1, $entry->installments);
        $this->assertSame('2500.00', $entry->installments[0]->amount);
        $this->assertSame('2026-10-10', $entry->installments[0]->due_date->format('Y-m-d'));
        // Pago na data do lançamento.
        $this->assertSame('2026-10-05', $entry->installments[0]->paid_at->format('Y-m-d'));
        $this->assertDatabaseHas('audit_logs', ['action' => AuditAction::FinancialEntryCreated->value, 'auditable_id' => $entry->id]);

        $this->get('/financeiro')->assertSee('Aluguel de outubro')->assertSee('R$ 2.500,00')->assertSee('Pago')->assertSee('À vista');
    }

    public function test_income_can_be_split_into_monthly_installments(): void
    {
        $this->actingAs($this->admin);
        $this->get('/financeiro/lancamentos/novo?tipo=income')->assertOk()->assertSee('Nova receita')->assertSee('Outras receitas')->assertDontSee('Aluguel e condomínio');

        $this->post('/financeiro/lancamentos', $this->payload([
            'type' => 'income', 'category' => FinancialCategory::OtherIncome->value, 'description' => 'Venda de dermocosmético',
            'total' => '100', 'payment_method' => 'credit_card', 'installments' => 3, 'first_due_date' => '2026-01-31', 'first_installment_paid' => '1',
        ]))->assertSessionHasNoErrors();

        $installments = FinancialEntry::sole()->installments()->orderBy('number')->get();

        $this->assertSame(['33.34', '33.33', '33.33'], $installments->pluck('amount')->all());
        $this->assertSame(['2026-01-31', '2026-02-28', '2026-03-31'], $installments->map(fn ($i) => $i->due_date->format('Y-m-d'))->all());
        // Receita no cartão de crédito: a maquininha repassa o total, então todas nascem recebidas.
        $this->assertSame([true, true, true], $installments->map(fn ($i) => $i->isPaid())->all());

        // Em outra forma de pagamento, só a primeira nasce paga.
        $this->post('/financeiro/lancamentos', $this->payload([
            'type' => 'income', 'category' => FinancialCategory::OtherIncome->value, 'description' => 'Venda no boleto',
            'total' => '100', 'payment_method' => 'bank_slip', 'installments' => 3, 'first_due_date' => '2026-01-31', 'first_installment_paid' => '1',
        ]))->assertSessionHasNoErrors();

        $boleto = FinancialEntry::where('description', 'Venda no boleto')->sole()->installments()->orderBy('number')->get();
        $this->assertSame([true, false, false], $boleto->map(fn ($i) => $i->isPaid())->all());
    }

    public function test_entry_data_is_validated(): void
    {
        $this->actingAs($this->admin);

        $invalid = [
            'type' => ['', 'transferencia'],
            // Categoria de receita em uma despesa, e "atendimento", que só nasce da finalização.
            'category' => ['', FinancialCategory::OtherIncome->value, FinancialCategory::Treatment->value, 'x'],
            'description' => ['', 'a', str_repeat('a', 201)],
            'issued_on' => ['', '08/10/2026', '2026-10-09', '1999-12-31'],
            'total' => ['', '0', '-10', '10,999', 'abc', '10000000'],
            'payment_method' => ['', 'cheque'],
            'installments' => ['', 0, 13, 'x'],
            'first_due_date' => ['', 'amanhã', '1999-01-01'],
        ];

        foreach ($invalid as $field => $values) {
            foreach ($values as $value) {
                $this->post('/financeiro/lancamentos', $this->payload([$field => $value]))->assertSessionHasErrors($field);
            }
        }

        // Receita não pode usar categoria de despesa.
        $this->post('/financeiro/lancamentos', $this->payload(['type' => 'income']))->assertSessionHasErrors('category');

        $this->assertSame(0, FinancialEntry::count());
    }

    public function test_monthly_summary_is_computed_correctly(): void
    {
        $this->entry('500', '2026-10-05', '2026-10-06');                              // receita recebida em outubro
        $this->entry('300', '2026-10-20');                                            // receita a vencer em outubro
        $this->entry('80', '2026-10-02');                                             // receita vencida em outubro
        $this->entry('1000', '2026-09-10', '2026-10-01', issuedOn: '2026-09-01');     // de setembro, recebida em outubro
        $this->entry('70', '2026-09-15', issuedOn: '2026-09-01');                     // vencida em setembro
        $this->entry('40', '2026-11-10', issuedOn: '2026-10-08');                     // lançada em outubro, vence em novembro
        $this->entry('200', '2026-10-03', '2026-10-03', expense: true);               // despesa paga
        $this->entry('150', '2026-10-25', expense: true);                             // despesa a pagar
        $this->entry('999', '2026-08-01', '2026-08-01', issuedOn: '2026-08-01');      // fora do período

        $summary = $this->actingAs($this->admin)->get('/financeiro')->assertOk()->viewData('summary');

        $this->assertSame(1500.0, $summary['received']);     // 500 + 1000
        $this->assertSame(380.0, $summary['receivable']);    // 300 + 80
        $this->assertSame(200.0, $summary['expenses']);
        $this->assertSame(150.0, $summary['payable']);
        $this->assertSame(1300.0, $summary['balance']);      // 1500 - 200
        $this->assertSame(920.0, $summary['revenue']);       // 500 + 300 + 80 + 40, lançadas em outubro
        $this->assertSame(150.0, $summary['overdue']);       // 80 + 70, em qualquer mês

        $september = $this->get('/financeiro?mes=2026-09')->viewData('summary');

        $this->assertSame(0.0, $september['received']);
        $this->assertSame(70.0, $september['receivable']);
        $this->assertSame(1070.0, $september['revenue']);
    }

    public function test_revenue_by_period_lists_the_last_six_months(): void
    {
        $this->entry('500', '2026-10-05', issuedOn: '2026-10-05');
        $this->entry('250', '2026-10-06', issuedOn: '2026-10-06');
        $this->entry('900', '2026-07-01', issuedOn: '2026-07-20');
        $this->entry('123', '2026-04-01', issuedOn: '2026-04-30');   // antes da janela de seis meses
        $this->entry('400', '2026-10-01', issuedOn: '2026-10-01', expense: true);

        $series = $this->actingAs($this->admin)->get('/financeiro')->viewData('revenueByMonth');

        $this->assertSame(['2026-05' => 0.0, '2026-06' => 0.0, '2026-07' => 900.0, '2026-08' => 0.0, '2026-09' => 0.0, '2026-10' => 750.0], $series);
    }

    public function test_listing_shows_the_month_and_can_be_filtered(): void
    {
        $this->entry('500', '2026-10-05', '2026-10-06', description: 'Receita Paga');
        $this->entry('300', '2026-10-20', description: 'Receita Pendente');
        $this->entry('80', '2026-10-02', description: 'Receita Vencida');
        $this->entry('200', '2026-10-03', '2026-10-03', expense: true, description: 'Despesa Paga');
        $this->entry('999', '2026-11-15', description: 'De Novembro');

        $this->actingAs($this->admin);

        $this->get('/financeiro')->assertSee('Receita Paga')->assertSee('Receita Pendente')->assertSee('Despesa Paga')->assertDontSee('De Novembro')
            ->assertSee('Vencido')->assertSee('Pendente')->assertSee('Outubro de 2026');
        $this->get('/financeiro?mes=2026-11')->assertSee('De Novembro')->assertDontSee('Receita Paga');
        $this->get('/financeiro?tipo=despesas')->assertSee('Despesa Paga')->assertDontSee('Receita Paga');
        $this->get('/financeiro?tipo=receitas')->assertSee('Receita Paga')->assertDontSee('Despesa Paga');
        $this->get('/financeiro?situacao=pagas')->assertSee('Receita Paga')->assertSee('Despesa Paga')->assertDontSee('Receita Pendente');
        $this->get('/financeiro?situacao=pendentes')->assertSee('Receita Pendente')->assertDontSee('Receita Vencida')->assertDontSee('Receita Paga');
        $this->get('/financeiro?situacao=vencidas')->assertSee('Receita Vencida')->assertDontSee('Receita Pendente');

        $this->get('/financeiro?mes=outubro')->assertSessionHasErrors('mes');
        $this->get('/financeiro?tipo=x')->assertSessionHasErrors('tipo');
        $this->get('/financeiro?situacao=x')->assertSessionHasErrors('situacao');
    }

    public function test_payment_can_be_registered_and_undone(): void
    {
        $installment = $this->entry('300', '2026-10-20')->installments()->first();

        $this->actingAs($this->admin);

        $this->post('/financeiro/pagamentos', ['installment_id' => $installment->id, 'paid_at' => '2026-10-09'])->assertSessionHasErrors('paid_at');
        $this->post('/financeiro/pagamentos', ['installment_id' => $installment->id, 'paid_at' => ''])->assertSessionHasErrors('paid_at');
        $this->post('/financeiro/pagamentos', ['installment_id' => 999999, 'paid_at' => '2026-10-08'])->assertSessionHasErrors('installment_id');
        $this->assertNull($installment->refresh()->paid_at);

        $this->post('/financeiro/pagamentos', ['installment_id' => $installment->id, 'paid_at' => '2026-10-07'])->assertSessionHasNoErrors();
        $this->assertSame('2026-10-07', $installment->refresh()->paid_at->format('Y-m-d'));

        // Parcela já paga não recebe outro pagamento.
        $this->post('/financeiro/pagamentos', ['installment_id' => $installment->id, 'paid_at' => '2026-10-08'])
            ->assertSessionHasErrors(['installment_id' => 'Esta parcela não está mais em aberto.']);

        $this->delete("/financeiro/pagamentos/{$installment->id}")->assertSessionHasNoErrors();
        $this->assertNull($installment->refresh()->paid_at);

        // Desfazer o que não está pago não existe.
        $this->delete("/financeiro/pagamentos/{$installment->id}")->assertNotFound();

        $this->assertDatabaseHas('audit_logs', ['action' => AuditAction::InstallmentPaid->value, 'auditable_id' => $installment->id, 'user_id' => $this->admin->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => AuditAction::InstallmentReopened->value, 'auditable_id' => $installment->id]);
    }

    public function test_manual_entries_can_be_deleted_but_treatment_entries_cannot(): void
    {
        $manual = $this->entry('100', '2026-10-10');
        $fromTreatment = FinancialEntry::factory()->withInstallment('800', '2026-10-10')->create([
            'category' => FinancialCategory::Treatment, 'treatment_id' => Treatment::factory()->create()->id,
        ]);

        $this->actingAs($this->admin);

        $this->get('/financeiro')->assertOk();
        $this->delete("/financeiro/lancamentos/{$fromTreatment->id}")->assertForbidden();
        $this->delete("/financeiro/lancamentos/{$manual->id}")->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('financial_entries', ['id' => $manual->id]);
        $this->assertSame(0, FinancialInstallment::where('financial_entry_id', $manual->id)->count());
        $this->assertDatabaseHas('financial_entries', ['id' => $fromTreatment->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => AuditAction::FinancialEntryDeleted->value, 'auditable_id' => $manual->id]);
    }

    public function test_payment_dialog_reopens_when_the_date_is_rejected(): void
    {
        $installment = $this->entry('300', '2026-10-20')->installments()->first();

        $html = $this->actingAs($this->admin)->followingRedirects()->from('/financeiro')
            ->post('/financeiro/pagamentos', ['_dialog' => 'installment-payment', 'installment_id' => $installment->id, 'paid_at' => '2026-12-01'])
            ->getContent();

        $this->assertMatchesRegularExpression('/<dialog id="installment-payment"[^>]*data-dialog-autoshow/', $html);
        $this->assertStringContainsString('A data do pagamento não pode estar no futuro.', $html);
        $this->assertStringContainsString('name="installment_id" value="'.$installment->id.'"', $html);
    }

    public function test_output_is_escaped(): void
    {
        $this->entry('100', '2026-10-10', description: '<script>alert(1)</script>');

        $this->actingAs($this->admin)->get('/financeiro')->assertOk()->assertDontSee('<script>alert(1)</script>', false);
    }
}
