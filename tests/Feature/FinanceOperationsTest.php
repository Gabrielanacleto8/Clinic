<?php

namespace Tests\Feature;

use App\Enums\AuditAction;
use App\Enums\FinancialCategory;
use App\Enums\PaymentMethod;
use App\Enums\Permission;
use App\Enums\UserRole;
use App\Http\Controllers\InstallmentPaymentController;
use App\Models\AuditLog;
use App\Models\FinancialEntry;
use App\Models\Patient;
use App\Models\Treatment;
use App\Models\User;
use App\Services\FinancialRecorder;
use App\Services\RolePermissions;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * O financeiro no dia a dia: caixa do dia, cobrança, correções e recebimento pela ficha.
 */
class FinanceOperationsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-08 13:00', 'America/Sao_Paulo'));
        $this->admin = User::factory()->admin()->create();
    }

    private function income(string $total, int $installments = 1, string $firstDueDate = '2026-10-08', PaymentMethod $method = PaymentMethod::Pix, ?Patient $patient = null, ?Treatment $treatment = null, string $issuedOn = '2026-10-08', bool $paid = false): FinancialEntry
    {
        return app(FinancialRecorder::class)->record(
            category: $treatment ? FinancialCategory::Treatment : FinancialCategory::OtherIncome,
            description: $treatment ? 'Atendimento: Toxina' : 'Venda de produto',
            total: $total,
            paymentMethod: $method,
            installments: $installments,
            firstDueDate: $firstDueDate,
            firstInstallmentPaid: $paid,
            createdBy: $this->admin,
            issuedOn: $issuedOn,
            patient: $patient,
            treatment: $treatment,
        );
    }

    private function expense(string $total, string $dueDate = '2026-10-10', bool $paid = false, int $installments = 1): FinancialEntry
    {
        return app(FinancialRecorder::class)->record(
            category: FinancialCategory::Rent,
            description: 'Aluguel',
            total: $total,
            paymentMethod: PaymentMethod::BankSlip,
            installments: $installments,
            firstDueDate: $dueDate,
            firstInstallmentPaid: $paid,
            createdBy: $this->admin,
            issuedOn: '2026-10-05',
        );
    }

    // --- Excluir lançamento -------------------------------------------------

    public function test_an_entry_with_a_paid_installment_is_not_deleted_until_the_payment_is_undone(): void
    {
        $entry = $this->expense('300', '2026-10-10', installments: 3);
        $first = $entry->installments()->orderBy('number')->first();
        $first->update(['paid_at' => '2026-10-08']);

        $this->actingAs($this->admin);

        // O botão some da lista e a exclusão direta devolve a orientação, não 403.
        $this->get('/financeiro')->assertOk()->assertDontSee('Excluir o lançamento Aluguel');
        $this->from('/financeiro')->delete("/financeiro/lancamentos/{$entry->id}")
            ->assertRedirect('/financeiro')
            ->assertSessionHas('error', fn (string $message) => str_contains($message, 'Desfaça os pagamentos antes de excluir'));
        $this->assertDatabaseHas('financial_entries', ['id' => $entry->id]);

        $this->delete("/financeiro/pagamentos/{$first->id}")->assertSessionHasNoErrors();
        $this->get('/financeiro')->assertOk()->assertSee('Excluir o lançamento Aluguel (R$ 300,00, 3 parcelas)');
        $this->delete("/financeiro/lancamentos/{$entry->id}")->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('financial_entries', ['id' => $entry->id]);

        // A trilha guarda o que foi apagado: valores do lançamento, sem a descrição.
        $log = AuditLog::where('action', AuditAction::FinancialEntryDeleted->value)->sole();
        $this->assertEquals(
            ['type' => 'expense', 'category' => 'rent', 'issued_on' => '2026-10-05', 'total' => '300.00', 'installments' => 3, 'payment_method' => 'bank_slip'],
            $log->metadata,
        );
        $this->assertStringNotContainsString('Aluguel', $log->toJson());
    }

    public function test_the_delete_action_appears_once_per_entry(): void
    {
        $this->expense('300', '2026-10-01', installments: 3);

        $html = $this->actingAs($this->admin)->get('/financeiro?de=2026-10-01&ate=2026-12-31')->assertOk()->getContent();

        $this->assertSame(3, substr_count($html, 'data-label="Vencimento"'));
        $this->assertSame(1, substr_count($html, '>Excluir</button>'));
    }

    // --- Período, caixa do dia e vencidas ------------------------------------

    public function test_cash_of_the_day_by_payment_method_and_free_period(): void
    {
        $this->income('100', method: PaymentMethod::Pix, paid: true);
        $this->income('50', method: PaymentMethod::Cash, paid: true);
        $this->income('80', method: PaymentMethod::Cash, paid: true);
        $this->income('900', firstDueDate: '2026-10-01', issuedOn: '2026-10-01', paid: true);  // outro dia
        $this->expense('40', '2026-10-08', paid: true);

        $this->actingAs($this->admin);

        $today = $this->get('/financeiro?de=2026-10-08&ate=2026-10-08')->assertOk()->assertSee('08/10/2026');
        $this->assertSame(['cash' => 130.0, 'pix' => 100.0], $today->viewData('receivedByMethod'));
        $this->assertSame(230.0, $today->viewData('summary')['received']);
        $this->assertSame(270.0, $today->viewData('listingTotal'));  // 100 + 50 + 80 + 40

        // "mes" continua valendo para os links antigos; o período anterior é o mês anterior.
        $month = $this->get('/financeiro?mes=2026-10')->assertOk();
        $this->assertSame(1130.0, $month->viewData('summary')['received']);
        $this->assertSame(['pix' => 1000.0, 'cash' => 130.0], $month->viewData('receivedByMethod'));
        $this->assertSame(0.0, $month->viewData('previous')['received']);

        // Só "de": vai até o fim daquele mês; "até" antes de "de" é recusado.
        $this->assertSame('2026-10-31', $this->get('/financeiro?de=2026-10-02')->viewData('end'));
        $this->get('/financeiro?de=2026-10-08&ate=2026-10-01')->assertSessionHasErrors('ate');
        $this->get('/financeiro?de=08/10/2026')->assertSessionHasErrors('de');
    }

    public function test_comparison_with_the_previous_period(): void
    {
        $this->income('200', firstDueDate: '2026-09-10', issuedOn: '2026-09-10', paid: true);
        $this->income('300', paid: true);

        $view = $this->actingAs($this->admin)->get('/financeiro?mes=2026-10')->assertOk()->assertSee('+50% vs período anterior');

        $this->assertSame(200.0, $view->viewData('previous')['received']);

        // Dez dias comparam com os dez dias anteriores.
        $this->income('10', firstDueDate: '2026-09-25', issuedOn: '2026-09-25', paid: true);
        $window = $this->get('/financeiro?de=2026-10-01&ate=2026-10-10');
        $this->assertSame(10.0, $window->viewData('previous')['received']);
    }

    public function test_overdue_from_every_month_and_patient_filter(): void
    {
        $marina = Patient::factory()->create(['name' => 'Marina Albuquerque']);
        $joao = Patient::factory()->create(['name' => 'João Pereira']);
        $this->income('80', firstDueDate: '2026-08-02', issuedOn: '2026-08-01', patient: $marina);   // vencida há dois meses
        $this->income('30', firstDueDate: '2026-10-02', issuedOn: '2026-10-01', patient: $joao);     // vencida neste mês
        $this->income('50', firstDueDate: '2026-10-20', patient: $marina);                            // a vencer

        $this->actingAs($this->admin);

        $all = $this->get('/financeiro?tipo=receitas&situacao=vencidas_todas')->assertOk()
            ->assertSee('Parcelas vencidas, todos os meses')->assertSee('Marina Albuquerque')->assertSee('João Pereira');
        $this->assertSame(2, $all->viewData('installments')->total());
        $this->assertSame(110.0, $all->viewData('listingTotal'));

        // O alerta de vencidas leva a essa lista, e o nome do paciente leva à aba financeira dele.
        $this->get('/financeiro')->assertOk()
            ->assertSee(e(route('finance.index', ['tipo' => 'receitas', 'situacao' => 'vencidas_todas'])), false)
            ->assertSee(e(route('patients.show', [$marina, 'aba' => 'financeiro'])), false);

        $filtered = $this->get('/financeiro?paciente=Marina&situacao=abertas')->assertOk()->assertSee('Marina Albuquerque')->assertDontSee('João Pereira');
        $this->assertSame(1, $filtered->viewData('installments')->total());

        // Paciente arquivado continua encontrável pelo nome.
        $marina->delete();
        $this->get('/financeiro?paciente=Marina&situacao=vencidas_todas')->assertOk()->assertSee('Marina Albuquerque');

        // O nome buscado não vai para a auditoria.
        $this->assertStringNotContainsString('Marina', AuditLog::where('action', AuditAction::FinanceViewed->value)->get()->toJson());
    }

    // --- Receber: forma de pagamento, cartão e pela ficha --------------------

    public function test_receiving_confirms_the_payment_method_and_audits_the_change(): void
    {
        $entry = $this->income('100');
        $installment = $entry->installments()->sole();

        $this->actingAs($this->admin)
            ->post('/financeiro/pagamentos', ['installment_id' => $installment->id, 'paid_at' => '2026-10-08', 'payment_method' => 'cash'])
            ->assertSessionHasNoErrors();

        $this->assertSame(PaymentMethod::Cash, $entry->refresh()->payment_method);
        $this->assertEquals(
            ['fields' => ['payment_method'], 'from' => 'pix', 'to' => 'cash'],
            AuditLog::where('action', AuditAction::FinancialEntryUpdated->value)->sole()->metadata,
        );

        // Forma igual à lançada não gera alteração.
        $other = $this->income('100')->installments()->sole();
        $this->post('/financeiro/pagamentos', ['installment_id' => $other->id, 'paid_at' => '2026-10-08', 'payment_method' => 'pix'])->assertSessionHasNoErrors();
        $this->assertSame(1, AuditLog::where('action', AuditAction::FinancialEntryUpdated->value)->count());

        $this->post('/financeiro/pagamentos', ['installment_id' => $this->income('5')->installments()->sole()->id, 'paid_at' => '2026-10-08', 'payment_method' => 'cheque'])
            ->assertSessionHasErrors('payment_method');
    }

    public function test_payment_method_does_not_switch_to_or_from_credit_card_on_an_installment_plan(): void
    {
        $card = $this->income('300', 3, method: PaymentMethod::CreditCard);
        $pix = $this->income('300', 3);

        $this->actingAs($this->admin);

        $this->post('/financeiro/pagamentos', ['installment_id' => $card->installments()->first()->id, 'paid_at' => '2026-10-08', 'payment_method' => 'pix'])
            ->assertSessionHasErrors(['payment_method' => InstallmentPaymentController::CARD_SWITCH_MESSAGE]);
        $this->post('/financeiro/pagamentos', ['installment_id' => $pix->installments()->first()->id, 'paid_at' => '2026-10-08', 'payment_method' => 'credit_card'])
            ->assertSessionHasErrors('payment_method');
        $this->assertSame(0, $card->installments()->whereNotNull('paid_at')->count());

        // Com uma parcela já paga, a forma não muda mais.
        $this->post('/financeiro/pagamentos', ['installment_id' => $pix->installments()->orderBy('number')->first()->id, 'paid_at' => '2026-10-08'])->assertSessionHasNoErrors();
        $this->post('/financeiro/pagamentos', ['installment_id' => $pix->installments()->orderBy('number')->skip(1)->first()->id, 'paid_at' => '2026-10-08', 'payment_method' => 'cash'])
            ->assertSessionHasErrors('payment_method');
    }

    public function test_credit_card_receive_button_says_it_settles_every_installment(): void
    {
        $this->income('1000', 10, method: PaymentMethod::CreditCard);

        $this->actingAs($this->admin)->get('/financeiro?de=2026-10-01&ate=2027-12-31')->assertOk()
            ->assertSee('recebe as 10 parcelas do cartão de crédito, R$ 1.000,00')
            ->assertSee('Confirmar o recebimento das 10 parcelas (R$ 1.000,00)?');
    }

    public function test_payment_dialog_keeps_the_summary_after_a_validation_error(): void
    {
        $entry = $this->income('300', 3, method: PaymentMethod::DebitCard);
        $installment = $entry->installments()->orderBy('number')->skip(1)->first();

        $html = $this->actingAs($this->admin)->followingRedirects()->from('/financeiro')
            ->post('/financeiro/pagamentos', ['_dialog' => 'installment-payment', 'installment_id' => $installment->id, 'paid_at' => '2026-12-01'])
            ->getContent();

        $this->assertStringContainsString('Venda de produto', $html);
        $this->assertMatchesRegularExpression('/data-dialog-summary>\s*Venda de produto\s*· Parcela 2\/3\s*· R\$ 100,00/', $html);
        $this->assertMatchesRegularExpression('/<option value="debit_card" selected/', $html);
    }

    public function test_reception_receives_from_the_patient_record_without_seeing_the_finance_module(): void
    {
        $patient = Patient::factory()->create(['name' => 'Marina Albuquerque']);
        $entry = $this->income('150', patient: $patient);
        $installment = $entry->installments()->sole();
        $receptionist = User::factory()->create();

        $this->actingAs($receptionist);
        $this->get('/financeiro')->assertForbidden();
        $this->get("/pacientes/{$patient->id}?aba=financeiro")->assertOk()
            ->assertSee('Vencido')->assertSee('A vencer')->assertSee('R$ 150,00')
            ->assertSee('data-dialog-open="installment-payment"', false)->assertSee('id="installment-payment"', false);

        $this->from("/pacientes/{$patient->id}?aba=financeiro")
            ->post('/financeiro/pagamentos', ['installment_id' => $installment->id, 'paid_at' => '2026-10-08', 'payment_method' => 'cash'])
            ->assertRedirect("/pacientes/{$patient->id}?aba=financeiro")
            ->assertSessionHasNoErrors();
        $this->assertNotNull($installment->refresh()->paid_at);

        // Sem a permissão de receber, nem o botão nem a rota.
        app(RolePermissions::class)->sync(UserRole::Receptionist, [Permission::PatientsView, Permission::FinanceView]);
        $this->actingAs(User::factory()->create())->get("/pacientes/{$patient->id}?aba=financeiro")->assertOk()->assertDontSee('data-dialog-open="installment-payment"', false);
        $this->post('/financeiro/pagamentos', ['installment_id' => $this->income('10', patient: $patient)->installments()->sole()->id, 'paid_at' => '2026-10-08'])->assertForbidden();
    }

    public function test_reception_does_not_pay_expenses(): void
    {
        $expense = $this->expense('40');

        $this->actingAs(User::factory()->create())
            ->post('/financeiro/pagamentos', ['installment_id' => $expense->installments()->sole()->id, 'paid_at' => '2026-10-08'])
            ->assertForbidden();
    }

    public function test_patient_finance_tab_runs_a_fixed_number_of_queries(): void
    {
        $patient = Patient::factory()->create();
        $count = function () use ($patient): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->get("/pacientes/{$patient->id}?aba=financeiro")->assertOk();
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        $this->actingAs($this->admin);
        $this->income('100', patient: $patient);
        // A primeira leitura grava a auditoria; as seguintes, em poucos minutos, não. Compara leituras já auditadas.
        $count();
        $withOne = $count();

        foreach (range(1, 6) as $_) {
            $this->income('100', 2, patient: $patient);
        }

        $this->assertSame($withOne, $count());
    }

    // --- Ajustar cobrança do atendimento --------------------------------------

    public function test_the_charge_of_a_treatment_is_adjusted_while_nothing_was_received(): void
    {
        $patient = Patient::factory()->create();
        $treatment = Treatment::factory()->create(['patient_id' => $patient->id]);
        $entry = $this->income('900', 1, method: PaymentMethod::Pix, patient: $patient, treatment: $treatment);
        $url = "/financeiro/lancamentos/{$entry->id}/cobranca";

        $this->actingAs($this->admin)->get('/financeiro')->assertOk()->assertSee('>Ajustar cobrança</button>', false);

        $this->put($url, ['payment_method' => 'credit_card', 'installments' => 3, 'first_due_date' => '2026-11-05'])->assertSessionHasNoErrors();

        $entry->refresh();
        $this->assertSame(PaymentMethod::CreditCard, $entry->payment_method);
        $this->assertSame(['300.00', '300.00', '300.00'], $entry->installments()->orderBy('number')->pluck('amount')->all());
        $this->assertSame(['2026-11-05', '2026-12-05', '2027-01-05'], $entry->installments()->orderBy('number')->get()->map(fn ($i) => $i->due_date->format('Y-m-d'))->all());
        $this->assertSame($treatment->id, $entry->treatment_id);

        $log = AuditLog::where('action', AuditAction::FinancialEntryUpdated->value)->sole();
        $this->assertEquals(['payment_method' => 'pix', 'installments' => 1, 'first_due_date' => '2026-10-08'], $log->metadata['from']);
        $this->assertSame($treatment->id, $log->metadata['treatment_id']);
        $this->assertSame($patient->id, $log->metadata['patient_id']);

        // Parcela que não cabe e dados inválidos são recusados.
        $this->put($url, ['payment_method' => 'pix', 'installments' => 0, 'first_due_date' => '2026-11-05'])->assertSessionHasErrors('installments');
        $this->put($url, ['payment_method' => 'pix', 'installments' => 2, 'first_due_date' => 'amanhã'])->assertSessionHasErrors('first_due_date');

        // Recebida uma parcela, a cobrança não se ajusta mais, e o botão some.
        $entry->installments()->orderBy('number')->first()->update(['paid_at' => '2026-10-08']);
        $this->put($url, ['payment_method' => 'pix', 'installments' => 1, 'first_due_date' => '2026-11-05'])->assertForbidden();
        $this->get('/financeiro?de=2026-11-01&ate=2027-01-31')->assertOk()->assertDontSee('>Ajustar cobrança</button>', false);
    }

    public function test_only_finance_managers_adjust_a_charge_and_manual_entries_are_not_adjusted(): void
    {
        $treatment = Treatment::factory()->create();
        $entry = $this->income('900', treatment: $treatment, patient: $treatment->patient);
        $manual = $this->income('100');
        $payload = ['payment_method' => 'cash', 'installments' => 1, 'first_due_date' => '2026-10-08'];

        $this->put("/financeiro/lancamentos/{$entry->id}/cobranca", $payload)->assertRedirect('/login');
        $this->actingAs(User::factory()->create())->put("/financeiro/lancamentos/{$entry->id}/cobranca", $payload)->assertForbidden();
        $this->actingAs(User::factory()->professional()->create())->put("/financeiro/lancamentos/{$entry->id}/cobranca", $payload)->assertForbidden();
        $this->actingAs($this->admin)->put("/financeiro/lancamentos/{$manual->id}/cobranca", $payload)->assertForbidden();

        $this->assertSame(PaymentMethod::Pix, $entry->refresh()->payment_method);
    }

    // --- Repetir, editar e receita com paciente -------------------------------

    public function test_an_expense_can_be_repeated_with_todays_date(): void
    {
        $previous = $this->expense('2500.00', '2026-09-10', paid: true);

        $this->actingAs($this->admin);

        $this->get('/financeiro?mes=2026-09')->assertOk()->assertSee(e(route('finance.entries.create', ['repetir' => $previous->id])), false);
        $this->get("/financeiro/lancamentos/novo?repetir={$previous->id}")->assertOk()
            ->assertSee('Dados copiados do lançamento anterior')
            ->assertSee('value="Aluguel"', false)
            ->assertSee('value="2500,00"', false)
            ->assertSee('<option value="rent" selected', false)
            ->assertSee('<option value="bank_slip" selected', false)
            ->assertSee('value="2026-10-08"', false);

        $this->get('/financeiro/lancamentos/novo?repetir=abc')->assertSessionHasErrors('repetir');
        $this->get('/financeiro/lancamentos/novo?repetir=999999')->assertOk()->assertDontSee('Dados copiados');
    }

    public function test_a_manual_entry_can_have_description_category_and_open_due_dates_edited(): void
    {
        $entry = $this->expense('300', '2026-10-10', installments: 3);
        [$first, $second, $third] = $entry->installments()->orderBy('number')->get();
        $first->update(['paid_at' => '2026-10-08']);
        $url = "/financeiro/lancamentos/{$entry->id}";

        $this->actingAs($this->admin);
        $this->get("$url/editar")->assertOk()->assertSee('Paga em 08/10/2026')->assertSee("due_dates[{$second->id}]", false);

        $this->put($url, [
            'description' => 'Aluguel de outubro',
            'category' => 'rent',
            'due_dates' => [$second->id => '2026-11-20', $third->id => '2026-12-10'],
        ])->assertSessionHasNoErrors();

        $this->assertSame('Aluguel de outubro', $entry->refresh()->description);
        $this->assertSame('2026-11-20', $second->refresh()->due_date->format('Y-m-d'));
        $this->assertSame('2026-12-10', $third->refresh()->due_date->format('Y-m-d'));
        $this->assertSame('2026-10-08', $first->refresh()->paid_at->format('Y-m-d'));
        $this->assertEquals(['fields' => ['description', 'due_date']], AuditLog::where('action', AuditAction::FinancialEntryUpdated->value)->sole()->metadata);

        // Parcela paga não é prorrogada; categoria de outro tipo é recusada.
        $this->put($url, ['description' => 'Aluguel', 'category' => 'rent', 'due_dates' => [$first->id => '2026-12-01']])->assertSessionHasErrors('due_dates');
        $this->put($url, ['description' => 'Aluguel', 'category' => 'other_income'])->assertSessionHasErrors('category');
    }

    public function test_treatment_entries_are_not_edited_and_editing_needs_the_finance_permission(): void
    {
        $treatment = Treatment::factory()->create();
        $fromTreatment = $this->income('900', treatment: $treatment, patient: $treatment->patient);
        $manual = $this->expense('40');

        $this->actingAs($this->admin);
        $this->get("/financeiro/lancamentos/{$fromTreatment->id}/editar")->assertForbidden();
        $this->put("/financeiro/lancamentos/{$fromTreatment->id}", ['description' => 'x', 'category' => 'other_income'])->assertForbidden();

        $this->actingAs(User::factory()->create())->get("/financeiro/lancamentos/{$manual->id}/editar")->assertForbidden();
        $this->get("/financeiro/lancamentos/{$manual->id}/editar")->assertForbidden();
    }

    public function test_manual_income_can_be_linked_to_a_patient(): void
    {
        $patient = Patient::factory()->create(['name' => 'Marina Albuquerque']);

        $this->actingAs($this->admin);
        $this->get('/financeiro/lancamentos/novo?tipo=income')->assertOk()->assertSee('data-patient-picker', false)->assertSee('(opcional)');
        $this->get('/financeiro/lancamentos/novo?tipo=expense')->assertOk()->assertDontSee('data-patient-picker', false);

        $payload = [
            'type' => 'income', 'category' => 'other_income', 'description' => 'Kit pós-procedimento', 'issued_on' => '2026-10-08',
            'total' => '120,00', 'payment_method' => 'pix', 'installments' => 1, 'first_due_date' => '2026-10-08', 'first_installment_paid' => '1',
        ];

        $this->post('/financeiro/lancamentos', [...$payload, 'patient_id' => $patient->id])->assertSessionHasNoErrors();
        $this->assertSame($patient->id, FinancialEntry::sole()->patient_id);
        $this->assertSame($patient->id, AuditLog::where('action', AuditAction::FinancialEntryCreated->value)->sole()->metadata['patient_id']);

        $this->get("/pacientes/{$patient->id}?aba=financeiro")->assertOk()->assertSee('Kit pós-procedimento');

        $this->post('/financeiro/lancamentos', [...$payload, 'patient_id' => 999999])->assertSessionHasErrors('patient_id');
        $this->post('/financeiro/lancamentos', ['type' => 'expense', 'category' => 'rent', 'description' => 'Aluguel', 'issued_on' => '2026-10-08', 'total' => '10', 'payment_method' => 'pix', 'installments' => 1, 'first_due_date' => '2026-10-08', 'first_installment_paid' => '0', 'patient_id' => $patient->id])
            ->assertSessionHasErrors('patient_id');
    }

    public function test_finance_list_with_a_free_period_keeps_a_fixed_number_of_queries(): void
    {
        $this->actingAs($this->admin);
        $round = 0;

        // Cada rodada: receita de atendimento (com e sem pagamento), cartão em parcelas,
        // receita manual de paciente arquivado e despesa paga.
        $populate = function (int $count) use (&$round): void {
            foreach (range(1, $count) as $_) {
                $round++;
                $treatment = Treatment::factory()->create();
                $this->income('300', patient: $treatment->patient, treatment: $treatment);
                $paidTreatment = Treatment::factory()->create();
                $this->income('200', patient: $paidTreatment->patient, treatment: $paidTreatment, paid: true);
                $this->income('900', 3, '2026-10-05', PaymentMethod::CreditCard, Patient::factory()->create());
                $archived = Patient::factory()->create();
                $this->income('50', patient: $archived);
                $archived->delete();
                $this->expense('80', '2026-10-0'.(1 + $round % 9), paid: true);
            }
        };

        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->get('/financeiro?de=2026-10-01&ate=2026-10-15')->assertOk();
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        $populate(1);
        $count();
        $few = $count();

        $populate(4);
        $many = $count();

        $this->get('/financeiro?de=2026-10-01&ate=2026-10-15')
            ->assertSee('>Ajustar cobrança</button>', false)->assertSee('por forma de pagamento');
        $this->assertSame($few, $many);
    }

    public function test_editing_an_entry_sends_guests_to_login_and_refuses_who_does_not_manage_finance(): void
    {
        $entry = $this->expense('300', '2026-10-10');
        $payload = ['description' => 'Outro nome', 'category' => 'rent'];

        $this->get("/financeiro/lancamentos/{$entry->id}/editar")->assertRedirect('/login');
        $this->put("/financeiro/lancamentos/{$entry->id}", $payload)->assertRedirect('/login');

        // A recepção recebe de paciente, mas não edita lançamento; o profissional não vê o financeiro.
        $this->actingAs(User::factory()->create())->put("/financeiro/lancamentos/{$entry->id}", $payload)->assertForbidden();
        $this->actingAs(User::factory()->professional()->create())->put("/financeiro/lancamentos/{$entry->id}", $payload)->assertForbidden();

        // Só com o financeiro para ver, também não edita.
        app(RolePermissions::class)->sync(UserRole::Receptionist, [Permission::FinanceView, Permission::FinanceReceive]);
        $this->actingAs(User::factory()->create())->get("/financeiro/lancamentos/{$entry->id}/editar")->assertForbidden();

        $this->assertSame('Aluguel', $entry->refresh()->description);
        $this->assertSame(0, AuditLog::where('action', AuditAction::FinancialEntryUpdated->value)->count());
    }

    public function test_editing_an_entry_does_not_reach_installments_of_another_entry(): void
    {
        $entry = $this->expense('300', '2026-10-10', installments: 3);
        $other = $this->expense('100', '2026-10-20');
        $foreign = $other->installments()->sole();

        $this->actingAs($this->admin)->put("/financeiro/lancamentos/{$entry->id}", [
            'description' => 'Aluguel',
            'category' => 'rent',
            'due_dates' => [$foreign->id => '2027-01-01'],
        ])->assertSessionHasErrors('due_dates');

        $this->assertSame('2026-10-20', $foreign->refresh()->due_date->format('Y-m-d'));

        // Campos obrigatórios e formato de data.
        $this->put("/financeiro/lancamentos/{$entry->id}", ['description' => '', 'category' => ''])->assertSessionHasErrors(['description', 'category']);
        $first = $entry->installments()->orderBy('number')->first();
        $this->put("/financeiro/lancamentos/{$entry->id}", ['description' => 'Aluguel', 'category' => 'rent', 'due_dates' => [$first->id => '10/11/2026']])
            ->assertSessionHasErrors("due_dates.{$first->id}");
        $this->assertSame('2026-10-10', $first->refresh()->due_date->format('Y-m-d'));
    }

    public function test_adjusting_a_charge_validates_the_payment_method_and_keeps_the_total(): void
    {
        $treatment = Treatment::factory()->create();
        $entry = $this->income('100', patient: $treatment->patient, treatment: $treatment);
        $url = "/financeiro/lancamentos/{$entry->id}/cobranca";

        $this->actingAs($this->admin);
        $this->put($url, ['payment_method' => 'bitcoin', 'installments' => 1, 'first_due_date' => '2026-10-08'])->assertSessionHasErrors('payment_method');
        $this->put($url, ['payment_method' => 'pix', 'installments' => 1, 'first_due_date' => ''])->assertSessionHasErrors('first_due_date');
        // R$ 100,00 em 10.001 parcelas não cabe (cada uma precisa de pelo menos R$ 0,01).
        $this->put($url, ['payment_method' => 'pix', 'installments' => 10001, 'first_due_date' => '2026-10-08'])->assertSessionHasErrors('installments');

        $this->put($url, ['payment_method' => 'credit_card', 'installments' => 3, 'first_due_date' => '2026-10-08'])->assertSessionHasNoErrors();
        $this->assertSame('100.00', number_format((float) $entry->installments()->sum('amount'), 2, '.', ''));
        $this->assertSame(3, $entry->installments()->count());
    }
}
