<?php

namespace Tests\Feature;

use App\Enums\AuditAction;
use App\Enums\FinancialCategory;
use App\Enums\PaymentMethod;
use App\Enums\Permission;
use App\Enums\TreatmentPlanStatus;
use App\Models\AuditLog;
use App\Models\ClinicProfile;
use App\Models\FinancialEntry;
use App\Models\Patient;
use App\Models\Procedure;
use App\Models\Treatment;
use App\Models\TreatmentPlan;
use App\Models\TreatmentPlanItem;
use App\Models\User;
use App\Services\RolePermissions;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Orçamentos e pacotes de sessões: criar, aprovar (gera a cobrança), usar as
 * sessões no registro do atendimento e encerrar.
 */
class TreatmentPlanTest extends TestCase
{
    use RefreshDatabase;

    private User $professional;

    private Patient $patient;

    private Procedure $microneedling;

    private Procedure $peeling;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-08 13:00', 'America/Sao_Paulo'));
        $this->professional = User::factory()->professional()->create(['name' => 'Dra. Helena']);
        $this->patient = Patient::factory()->create(['name' => 'Mariana Costa']);
        $this->microneedling = $this->procedure('Microagulhamento', ['price' => 450]);
        $this->peeling = $this->procedure('Peeling químico', ['price' => 380]);

        // Estes testes não tratam do limite de desconto (ver FinanceDiscountTest): valores livres.
        DB::table('role_discount_limits')->update(['max_percent' => 100]);
    }

    private function plansUrl(?Patient $patient = null): string
    {
        return '/pacientes/'.($patient ?? $this->patient)->id.'/orcamentos';
    }

    /**
     * Novo orçamento pelo módulo, com o paciente escolhido no formulário.
     */
    private function storePlan(array $data): TestResponse
    {
        return $this->post('/orcamentos', ['patient_id' => $this->patient->id, ...$data]);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function createPlan(array $items = [], array $overrides = []): TreatmentPlan
    {
        $this->actingAs($this->professional)->storePlan([
            'valid_until' => '2026-10-23',
            'notes' => 'Intervalo de 30 dias entre sessões.',
            'items' => $items ?: [
                ['procedure_id' => $this->microneedling->id, 'sessions' => '4', 'unit_price' => '400,00'],
                ['procedure_id' => $this->peeling->id, 'sessions' => '1', 'unit_price' => '380,00'],
                ['procedure_id' => '', 'sessions' => '', 'unit_price' => ''],
            ],
            ...$overrides,
        ])->assertSessionHasNoErrors();

        return TreatmentPlan::query()->latest('id')->firstOrFail();
    }

    private function approve(TreatmentPlan $plan, array $overrides = []): TestResponse
    {
        return $this->post($this->plansUrl()."/{$plan->id}/aprovacao", [
            'payment_method' => PaymentMethod::CreditCard->value,
            'installments' => '3',
            'first_due_date' => '2026-10-08',
            ...$overrides,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function treatment(TreatmentPlanItem $item, array $overrides = []): array
    {
        return [
            'performed_at' => '2026-10-08T12:00',
            'procedure_id' => $item->procedure_id,
            'description' => 'Sessão do pacote.',
            'treatment_plan_item_id' => $item->id,
            'amount' => '450,00',
            'payment_method' => PaymentMethod::Pix->value,
            'installments' => 1,
            'first_due_date' => '2026-10-08',
            ...$overrides,
        ];
    }

    private function remaining(TreatmentPlanItem $item): int
    {
        return TreatmentPlanItem::query()->withUsedSessions()->findOrFail($item->id)->remainingSessions();
    }

    public function test_plan_is_created_with_items_and_encrypted_notes(): void
    {
        $plan = $this->createPlan();

        $this->assertSame(TreatmentPlanStatus::Open, $plan->status);
        $this->assertSame($this->professional->id, $plan->created_by);
        $this->assertSame([[$this->microneedling->id, 4, '400.00'], [$this->peeling->id, 1, '380.00']],
            $plan->items->map(fn ($item) => [$item->procedure_id, $item->sessions, $item->unit_price])->all());
        $this->assertSame('1980.00', $plan->total());
        $this->assertStringNotContainsString('Intervalo', (string) DB::table('treatment_plans')->value('notes'));
        $this->assertSame(2, AuditLog::where('action', AuditAction::TreatmentPlanCreated->value)->sole()->metadata['items']);

        $this->get($this->plansUrl()."/{$plan->id}")->assertOk()->assertSee('Orçamento nº '.$plan->id)->assertSee('R$ 1.980,00');
        $this->get("/pacientes/{$this->patient->id}?aba=orcamentos")->assertOk()->assertSee('Microagulhamento (4)');
    }

    public function test_plan_needs_items_without_repetition_from_active_procedures(): void
    {
        $this->actingAs($this->professional);
        $inactive = $this->procedure('Antigo', ['is_active' => false]);

        $this->storePlan(['items' => [['procedure_id' => '', 'sessions' => '', 'unit_price' => '']]])->assertSessionHasErrors('items');
        $this->storePlan(['items' => [
            ['procedure_id' => $this->peeling->id, 'sessions' => '1', 'unit_price' => '380'],
            ['procedure_id' => $this->peeling->id, 'sessions' => '2', 'unit_price' => '380'],
        ]])->assertSessionHasErrors('items');
        $this->storePlan(['items' => [['procedure_id' => $inactive->id, 'sessions' => '1', 'unit_price' => '10']]])->assertSessionHasErrors('items.0.procedure_id');
        $this->storePlan(['items' => [['procedure_id' => $this->peeling->id, 'sessions' => '0', 'unit_price' => '-1']]])->assertSessionHasErrors(['items.0.sessions', 'items.0.unit_price']);

        $this->assertSame(0, TreatmentPlan::count());
    }

    public function test_open_plan_is_edited_and_approval_generates_one_charge(): void
    {
        $plan = $this->createPlan();

        $this->put($this->plansUrl()."/{$plan->id}", [
            'valid_until' => '2026-10-30',
            'items' => [['procedure_id' => $this->microneedling->id, 'sessions' => '3', 'unit_price' => '420,00']],
        ])->assertRedirect($this->plansUrl()."/{$plan->id}");
        $this->assertSame('1260.00', $plan->fresh()->load('items')->total());

        $this->approve($plan)->assertRedirect($this->plansUrl()."/{$plan->id}")->assertSessionHasNoErrors();

        $plan->refresh();
        $this->assertSame(TreatmentPlanStatus::Approved, $plan->status);
        $this->assertSame($this->professional->id, $plan->approved_by);

        $entry = FinancialEntry::query()->where('treatment_plan_id', $plan->id)->sole();
        $this->assertSame(FinancialCategory::TreatmentPlan, $entry->category);
        $this->assertSame($this->patient->id, $entry->patient_id);
        $this->assertSame('1260.00', number_format((float) $entry->installments()->sum('amount'), 2, '.', ''));
        $this->assertSame(3, $entry->installments()->count());
        // O profissional não tem a permissão de receber: a cobrança nasce pendente.
        $this->assertSame(0, $entry->installments()->whereNotNull('paid_at')->count());

        // Aprovado vira histórico: não se edita nem se aprova de novo.
        $this->get($this->plansUrl()."/{$plan->id}/editar")->assertForbidden();
        $this->approve($plan)->assertForbidden();
        $this->assertSame(1, FinancialEntry::count());

        // A cobrança do pacote não é excluída à mão no financeiro.
        $this->actingAs(User::factory()->admin()->create())->delete("/financeiro/lancamentos/{$entry->id}")->assertForbidden();
    }

    public function test_a_procedure_is_removed_from_an_open_plan(): void
    {
        $plan = $this->createPlan();

        // Duas linhas preenchidas e duas em branco: "Remover" só aparece nas preenchidas.
        $page = $this->get($this->plansUrl()."/{$plan->id}/editar")->assertOk()->getContent();
        $this->assertSame(4, preg_match_all('/data-plan-remove\b/', $page));
        $this->assertSame(2, preg_match_all('/data-plan-remove\s+hidden/', $page));

        // A linha removida volta em branco, no meio da lista, e não é gravada.
        $this->put($this->plansUrl()."/{$plan->id}", [
            'valid_until' => '2026-10-23',
            'items' => [
                ['procedure_id' => '', 'sessions' => '', 'unit_price' => ''],
                ['procedure_id' => $this->peeling->id, 'sessions' => '1', 'unit_price' => '380,00'],
            ],
        ])->assertSessionHasNoErrors();

        $this->assertSame([$this->peeling->id], $plan->fresh()->items->pluck('procedure_id')->all());
        $this->assertSame('380.00', $plan->fresh()->total());
    }

    public function test_free_package_creates_no_charge_and_expired_quote_is_not_approved(): void
    {
        $free = $this->createPlan([['procedure_id' => $this->peeling->id, 'sessions' => '1', 'unit_price' => '0']]);
        $this->approve($free, ['installments' => '1'])->assertSessionHasNoErrors();
        $this->assertSame(TreatmentPlanStatus::Approved, $free->fresh()->status);
        $this->assertSame(0, FinancialEntry::count());

        $expired = $this->createPlan(overrides: ['valid_until' => '2026-10-01']);
        $this->approve($expired)->assertSessionHasErrors('payment_method');
        $this->assertSame(TreatmentPlanStatus::Open, $expired->fresh()->status);
        $this->get($this->plansUrl()."/{$expired->id}")->assertSee('Validade vencida');
    }

    public function test_quote_can_be_declined_and_package_can_be_closed(): void
    {
        $quote = $this->createPlan();
        $this->post($this->plansUrl()."/{$quote->id}/encerramento")->assertForbidden();
        $this->post($this->plansUrl()."/{$quote->id}/recusa")->assertRedirect();
        $this->assertSame(TreatmentPlanStatus::Declined, $quote->fresh()->status);
        $this->approve($quote)->assertForbidden();

        $package = $this->createPlan();
        $this->approve($package);
        $this->post($this->plansUrl()."/{$package->id}/recusa")->assertForbidden();
        $this->post($this->plansUrl()."/{$package->id}/encerramento")->assertRedirect();
        $this->assertSame(TreatmentPlanStatus::Closed, $package->fresh()->status);

        // Sessão de pacote encerrado não pode mais ser usada.
        $item = $package->items()->first();
        $this->post("/pacientes/{$this->patient->id}/atendimentos", $this->treatment($item))->assertSessionHasErrors('treatment_plan_item_id');

        $this->assertSame(1, AuditLog::where('action', AuditAction::TreatmentPlanDeclined->value)->count());
        $this->assertSame(1, AuditLog::where('action', AuditAction::TreatmentPlanClosed->value)->count());
    }

    public function test_sessions_are_used_by_treatments_without_a_new_charge_and_return_when_voided(): void
    {
        $plan = $this->createPlan([['procedure_id' => $this->microneedling->id, 'sessions' => '2', 'unit_price' => '400']]);
        $this->approve($plan);
        $item = $plan->items()->first();
        $chargesBefore = FinancialEntry::count();

        $this->get("/pacientes/{$this->patient->id}/atendimentos/novo")->assertOk()->assertSee('Sessão de pacote')->assertSee('restam 2 de 2');

        $this->post("/pacientes/{$this->patient->id}/atendimentos", $this->treatment($item))->assertSessionHasNoErrors();
        $treatment = Treatment::query()->latest('id')->firstOrFail();
        $this->assertSame($item->id, $treatment->treatment_plan_item_id);
        $this->assertSame($chargesBefore, FinancialEntry::count());
        $this->assertSame(1, $this->remaining($item));

        // Procedimento diferente do pacote é recusado.
        $this->post("/pacientes/{$this->patient->id}/atendimentos", $this->treatment($item, ['procedure_id' => $this->peeling->id]))->assertSessionHasErrors('treatment_plan_item_id');

        $this->post("/pacientes/{$this->patient->id}/atendimentos", $this->treatment($item))->assertSessionHasNoErrors();
        $this->assertSame(0, $this->remaining($item));
        $this->post("/pacientes/{$this->patient->id}/atendimentos", $this->treatment($item))->assertSessionHasErrors('treatment_plan_item_id');
        $this->assertSame(2, Treatment::count());

        // Anular devolve a sessão.
        $this->actingAs(User::factory()->admin()->create())
            ->post("/pacientes/{$this->patient->id}/atendimentos/{$treatment->id}/anulacao", ['reason' => 'Registrado errado.'])
            ->assertSessionHasNoErrors();
        $this->assertSame(1, $this->remaining($item));
        $this->assertSame($chargesBefore, FinancialEntry::count());
    }

    public function test_a_session_of_another_patient_cannot_be_used(): void
    {
        $plan = $this->createPlan();
        $this->approve($plan);
        $item = $plan->items()->first();
        $other = Patient::factory()->create();

        $this->post("/pacientes/{$other->id}/atendimentos", $this->treatment($item))->assertSessionHasErrors('treatment_plan_item_id');

        // Pelo diálogo, com o paciente escolhido no formulário, também.
        $this->post('/atendimentos', [...$this->treatment($item), 'patient_id' => $other->id])->assertSessionHasErrors('treatment_plan_item_id');
        $this->assertSame(0, Treatment::count());
    }

    public function test_dialog_lists_the_available_sessions_of_the_chosen_patient(): void
    {
        $plan = $this->createPlan();
        $this->approve($plan);

        $this->getJson("/pacientes/{$this->patient->id}/sessoes-de-pacote")
            ->assertOk()
            ->assertJsonCount(2, 'sessions')
            ->assertJsonPath('sessions.0.label', "Microagulhamento · pacote nº {$plan->id} · restam 4 de 4");

        $this->getJson('/pacientes/'.Patient::factory()->create()->id.'/sessoes-de-pacote')->assertOk()->assertJsonCount(0, 'sessions');

        $this->actingAs(User::factory()->create())->getJson("/pacientes/{$this->patient->id}/sessoes-de-pacote")->assertForbidden();
    }

    public function test_plan_is_created_from_the_module_choosing_the_patient(): void
    {
        // Na lista, "Novo orçamento" abre o diálogo com o formulário completo.
        $this->actingAs($this->professional)->get('/orcamentos')
            ->assertOk()
            ->assertSee('data-dialog-open="plan-create"', false)
            ->assertSee('<dialog id="plan-create"', false)
            ->assertSee('data-patient-picker', false)
            ->assertSee('data-plan-items', false)
            ->assertSee('Criar e converter em venda')
            ->assertDontSee('data-dialog-autoshow', false)
            ->assertDontSee('Escolher paciente');

        $this->get('/orcamentos/novo')->assertOk()->assertSee('data-patient-picker', false)->assertDontSee('Mariana Costa');
        // Vindo da ficha, o paciente já vem escolhido.
        $this->get("/orcamentos/novo?paciente={$this->patient->id}")->assertOk()->assertSee('Mariana Costa');
        $this->get("/pacientes/{$this->patient->id}?aba=orcamentos")->assertSee("/orcamentos/novo?paciente={$this->patient->id}", false);

        $items = [['procedure_id' => $this->peeling->id, 'sessions' => '1', 'unit_price' => '380,00']];
        $this->storePlan(['patient_id' => '', 'items' => $items])->assertSessionHasErrors('patient_id');
        $archived = Patient::factory()->create();
        $archived->delete();
        $this->storePlan(['patient_id' => $archived->id, 'items' => $items])->assertSessionHasErrors('patient_id');
        $this->assertSame(0, TreatmentPlan::count());

        // Erro no diálogo volta à lista com ele aberto, o erro e o paciente escolhido.
        $empty = ['_dialog' => 'plan-create', 'items' => [['procedure_id' => '', 'sessions' => '', 'unit_price' => '']]];
        $this->from('/orcamentos')->storePlan($empty)->assertRedirect('/orcamentos')->assertSessionHasErrors('items');
        $this->from('/orcamentos')->followingRedirects()->post('/orcamentos', ['patient_id' => $this->patient->id, ...$empty])
            ->assertOk()
            ->assertSee('data-dialog-autoshow', false)
            ->assertSee('Inclua ao menos um procedimento.')
            ->assertSee('value="'.$this->patient->id.'" data-patient-id', false);

        $other = Patient::factory()->create(['name' => 'Joana Lima']);
        $response = $this->storePlan(['_dialog' => 'plan-create', 'patient_id' => $other->id, 'items' => $items]);

        $plan = TreatmentPlan::query()->sole();
        $this->assertSame($other->id, $plan->patient_id);
        $response->assertRedirect("/pacientes/{$other->id}/orcamentos/{$plan->id}");

        // Editar não troca o paciente.
        $this->put($this->plansUrl($other)."/{$plan->id}", ['patient_id' => $this->patient->id, 'items' => $items])->assertSessionHasErrors('patient_id');
        $this->assertSame($other->id, $plan->fresh()->patient_id);
    }

    public function test_create_and_convert_opens_the_sale_with_the_payment_dialog(): void
    {
        $this->actingAs($this->professional);
        $response = $this->storePlan([
            'convert' => '1',
            'items' => [['procedure_id' => $this->peeling->id, 'sessions' => '2', 'unit_price' => '380,00']],
        ]);

        // Criar e converter só abre a conversão: a venda exige a forma de pagamento.
        $plan = TreatmentPlan::query()->sole();
        $this->assertSame(TreatmentPlanStatus::Open, $plan->status);
        $this->assertSame(0, FinancialEntry::count());
        $response->assertRedirect($this->plansUrl()."/{$plan->id}?vender=1");

        $this->get($this->plansUrl()."/{$plan->id}?vender=1")->assertOk()->assertSee('data-dialog-autoshow', false)->assertSee('Converter em venda');
        $this->get($this->plansUrl()."/{$plan->id}")->assertOk()->assertDontSee('data-dialog-autoshow', false);
        // Na lista, a conversão abre no próprio lugar, apontando para este orçamento.
        // O botão fica na coluna de ações da linha, no lugar do "Abrir" dos orçamentos que não se convertem.
        $this->get('/orcamentos')->assertSee('data-dialog-action="'.e(route('patients.plans.approve', [$this->patient, $plan])).'"', false)->assertSee('<dialog id="plan-approval"', false)
            ->assertSeeText('Ações')
            ->assertSeeInOrder(['Sessões restantes', 'Ações', 'Peeling químico (2)', 'Converter em venda'])
            ->assertDontSeeText("Abrir orçamento nº {$plan->id}");

        $this->approve($plan)->assertSessionHasNoErrors();
        $this->assertSame(TreatmentPlanStatus::Approved, $plan->fresh()->status);
        $this->assertSame(1, FinancialEntry::count());
        $this->get($this->plansUrl()."/{$plan->id}")->assertSee('Vendido')->assertDontSee('data-dialog-open="plan-approval"', false);
        $this->get('/orcamentos')->assertDontSee("/orcamentos/{$plan->id}/aprovacao", false)->assertSeeText("Abrir orçamento nº {$plan->id}");
    }

    public function test_a_tampered_plan_id_does_not_reopen_the_sale_dialog_for_another_plan(): void
    {
        $this->actingAs($this->professional);
        $items = [['procedure_id' => $this->peeling->id, 'sessions' => '1', 'unit_price' => '380,00']];
        $open = $this->createPlan($items);

        $sold = $this->createPlan($items);
        $this->approve($sold)->assertSessionHasNoErrors();

        $archivedPatient = Patient::factory()->create();
        $this->storePlan(['patient_id' => $archivedPatient->id, 'items' => $items])->assertSessionHasNoErrors();
        $ofArchived = TreatmentPlan::query()->latest('id')->firstOrFail();
        $archivedPatient->delete();

        // O plan_id só reabre o diálogo; a venda é sempre do orçamento da URL.
        foreach ([$sold->id, $ofArchived->id, 999999] as $planId) {
            $this->from('/orcamentos')
                ->approve($open, ['payment_method' => '', '_dialog' => 'plan-approval', 'voltar' => 'lista', 'plan_id' => $planId])
                ->assertRedirect('/orcamentos')
                ->assertSessionHasErrors('payment_method');

            $page = $this->get('/orcamentos')->assertOk();
            $page->assertDontSee('data-dialog-autoshow', false);
            foreach ([$sold, $ofArchived] as $other) {
                $page->assertDontSee('action="'.e(route('patients.plans.approve', [$other->patient_id, $other])).'"', false);
            }
        }

        $this->assertSame(TreatmentPlanStatus::Open, $open->fresh()->status);
        $this->assertSame(TreatmentPlanStatus::Open, $ofArchived->fresh()->status);
    }

    public function test_converting_from_the_list_keeps_the_user_on_the_list(): void
    {
        $this->actingAs($this->professional);
        $this->storePlan(['items' => [['procedure_id' => $this->peeling->id, 'sessions' => '2', 'unit_price' => '380,00']]]);
        $plan = TreatmentPlan::query()->sole();

        // Erro de validação: a lista reabre o diálogo já apontando para o orçamento.
        $this->from('/orcamentos?situacao=abertos')
            ->approve($plan, ['payment_method' => '', '_dialog' => 'plan-approval', 'voltar' => 'lista', 'plan_id' => $plan->id])
            ->assertRedirect('/orcamentos?situacao=abertos')
            ->assertSessionHasErrors('payment_method');
        $this->get('/orcamentos?situacao=abertos')->assertOk()
            ->assertSee('data-dialog-autoshow', false)
            ->assertSee('action="'.e(route('patients.plans.approve', [$this->patient, $plan])).'"', false);

        $this->from('/orcamentos?situacao=abertos')
            ->approve($plan, ['voltar' => 'lista', 'plan_id' => $plan->id])
            ->assertRedirect('/orcamentos?situacao=abertos')
            ->assertSessionHasNoErrors();
        $this->assertSame(TreatmentPlanStatus::Approved, $plan->fresh()->status);
        $this->assertSame('Ver o pacote', session('success_links')[0]['label']);

        // Destino desconhecido é recusado.
        $this->storePlan(['items' => [['procedure_id' => $this->peeling->id, 'sessions' => '1', 'unit_price' => '100,00']]]);
        $other = TreatmentPlan::query()->latest('id')->first();
        $this->approve($other, ['voltar' => 'https://exemplo.com'])->assertSessionHasErrors('voltar');
        $this->assertSame(TreatmentPlanStatus::Open, $other->fresh()->status);

        // "Voltar para a lista" vindo de outra página (ou de outro site) leva à lista limpa.
        $this->get("/pacientes/{$this->patient->id}?aba=orcamentos")->assertOk();
        $this->approve($other, ['voltar' => 'lista', 'plan_id' => $other->id])->assertRedirect('/orcamentos')->assertSessionHasNoErrors();

        $this->storePlan(['items' => [['procedure_id' => $this->peeling->id, 'sessions' => '1', 'unit_price' => '100,00']]]);
        $third = TreatmentPlan::query()->latest('id')->first();
        session()->setPreviousUrl('https://exemplo.com/orcamentos?situacao=abertos');
        $this->approve($third, ['voltar' => 'lista', 'plan_id' => $third->id])->assertRedirect('/orcamentos');
    }

    public function test_creating_from_the_module_needs_plan_and_patient_access(): void
    {
        $this->createPlan();
        $items = [['procedure_id' => $this->peeling->id, 'sessions' => '1', 'unit_price' => '380,00']];

        // Sem gerenciar orçamentos: só consulta, sem criar nem converter.
        $reception = User::factory()->create();
        DB::table('role_permissions')->where('role', 'receptionist')->where('permission', Permission::PlansManage->value)->delete();
        app(RolePermissions::class)->flush();

        $this->actingAs($reception)->get('/orcamentos')->assertOk()->assertDontSee('Novo orçamento')->assertDontSee('Converter em venda');
        $this->get('/orcamentos/novo')->assertForbidden();
        $this->storePlan(['items' => $items])->assertForbidden();

        // Sem ver pacientes: não há como escolher o paciente.
        DB::table('role_permissions')->insert(['role' => 'receptionist', 'permission' => Permission::PlansManage->value]);
        DB::table('role_permissions')->where('role', 'receptionist')->where('permission', Permission::PatientsView->value)->delete();
        app(RolePermissions::class)->flush();

        $this->get('/orcamentos')->assertOk()->assertDontSee('Novo orçamento');
        $this->get('/orcamentos/novo')->assertForbidden();
        $this->storePlan(['items' => $items])->assertForbidden();
        // Paciente inexistente também dá 403, não erro de validação: a resposta não revela IDs.
        $this->storePlan(['patient_id' => 999999, 'items' => $items])->assertForbidden();

        $this->assertSame(1, TreatmentPlan::count());
    }

    public function test_access_follows_the_plan_permissions(): void
    {
        $plan = $this->createPlan();

        // A recepção vende pacotes por padrão.
        $reception = User::factory()->create();
        $this->actingAs($reception)->get('/orcamentos')->assertOk()->assertSee('Mariana Costa');
        $this->get($this->plansUrl()."/{$plan->id}")->assertOk();

        DB::table('role_permissions')->where('role', 'receptionist')->where('permission', 'like', 'plans.%')->delete();
        app(RolePermissions::class)->flush();

        $this->get('/orcamentos')->assertForbidden();
        $this->get('/orcamentos/novo')->assertForbidden();
        $this->get($this->plansUrl()."/{$plan->id}")->assertForbidden();
        $this->get($this->plansUrl()."/{$plan->id}/imprimir")->assertForbidden();
        $this->approve($plan)->assertForbidden();
        $this->storePlan(['items' => [['procedure_id' => $this->peeling->id, 'sessions' => '1', 'unit_price' => '1']]])->assertForbidden();
        $this->get("/pacientes/{$this->patient->id}")->assertDontSee('Novo orçamento');

        // O orçamento só é alcançado pelo próprio paciente.
        $this->actingAs($this->professional)->get($this->plansUrl(Patient::factory()->create())."/{$plan->id}")->assertNotFound();
    }

    public function test_clicking_a_row_of_the_list_opens_the_plan_details(): void
    {
        $plan = $this->createPlan();
        $summaryUrl = route('patients.plans.summary', [$this->patient, $plan]);

        $this->get('/orcamentos')->assertOk()
            ->assertSee('<dialog id="plan-summary"', false)
            ->assertSee('data-summary="'.$summaryUrl.'" data-summary-dialog="plan-summary"', false)
            // Sem JavaScript, o número continua levando à página completa.
            ->assertSee('href="'.route('patients.plans.show', [$this->patient, $plan]).'" class="font-medium text-teal-800 hover:underline" data-summary-link', false);

        // Só o conteúdo, sem o layout, com o orçamento completo. A leitura é auditada.
        $this->get($summaryUrl)->assertOk()
            ->assertSee('data-summary-content', false)
            ->assertDontSee('<html', false)
            ->assertSeeInOrder(['Orçamento nº '.$plan->id, 'Orçamento em aberto', 'Mariana Costa', '2 procedimentos, 5 sessões'])
            ->assertSeeInOrder(['Valor do orçamento', 'R$ 1.980,00', 'Válido até', '23/10/2026'])
            ->assertSeeInOrder(['Microagulhamento', '4 sessões × R$ 400,00', 'R$ 1.600,00', 'Peeling químico', '1 sessão × R$ 380,00'])
            ->assertSee('Intervalo de 30 dias entre sessões.')
            ->assertDontSee('Histórico')
            // O orçamento inteiro, com as ações da página: a conversão usa o diálogo da lista.
            ->assertSee('data-dialog-action="'.e(route('patients.plans.approve', [$this->patient, $plan])).'"', false)
            ->assertSee('Editar')
            ->assertSee('Imprimir')
            ->assertSee('Paciente recusou')
            ->assertSee('name="voltar" value="lista"', false)
            ->assertDontSee('<h1', false)
            ->assertDontSee('página completa');
        $this->assertEquals(['patient_id' => $this->patient->id, 'view' => 'summary'],
            AuditLog::where('action', AuditAction::TreatmentPlanViewed->value)->sole()->metadata);

        // Vendido: pacote, sessões usadas e, só para quem vê o financeiro ou recebe, a cobrança.
        $this->approve($plan)->assertSessionHasNoErrors();
        $this->get($summaryUrl)->assertOk()
            ->assertSeeInOrder(['Pacote nº '.$plan->id, 'Vendido', 'Sessões usadas', '0 de 5'])
            ->assertDontSee('Recebido')
            ->assertDontSee('Converter em venda');
        $this->actingAs(User::factory()->create())->get($summaryUrl)->assertOk()
            ->assertSeeInOrder(['Recebido', 'R$ 0,00 de R$ 1.980,00', 'Cartão de crédito · 3 parcelas']);
    }

    public function test_declining_or_closing_from_the_list_dialog_returns_to_the_list(): void
    {
        $quote = $this->createPlan();
        $package = $this->createPlan();
        $this->approve($package)->assertSessionHasNoErrors();

        $this->get('/orcamentos?situacao=todos')->assertOk();
        $this->post($this->plansUrl()."/{$quote->id}/recusa", ['voltar' => 'lista'])
            ->assertRedirect('/orcamentos?situacao=todos')
            ->assertSessionHas('success', "Orçamento nº {$quote->id} marcado como recusado.");
        $this->assertSame(TreatmentPlanStatus::Declined, $quote->fresh()->status);

        $this->post($this->plansUrl()."/{$package->id}/encerramento", ['voltar' => 'lista'])->assertRedirect('/orcamentos?situacao=todos');
        $this->assertSame(TreatmentPlanStatus::Closed, $package->fresh()->status);

        // Pela página do orçamento, fica nela; destino desconhecido é recusado.
        $other = $this->createPlan();
        $this->post($this->plansUrl()."/{$other->id}/recusa", ['voltar' => 'https://exemplo.com'])->assertSessionHasErrors('voltar');
        $this->assertSame(TreatmentPlanStatus::Open, $other->fresh()->status);
        $this->post($this->plansUrl()."/{$other->id}/recusa")->assertRedirect($this->plansUrl()."/{$other->id}");
    }

    public function test_plan_details_follow_access_and_keep_a_constant_number_of_queries(): void
    {
        $plan = $this->createPlan();
        $summaryUrl = route('patients.plans.summary', [$this->patient, $plan]);

        // Outro paciente na URL: o orçamento não é alcançado.
        $this->get(route('patients.plans.summary', [Patient::factory()->create(), $plan]))->assertNotFound();

        $count = function () use ($summaryUrl): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->get($summaryUrl)->assertOk();

            return count(DB::getQueryLog());
        };

        $count();
        $before = $count();
        $plan->items()->create(['procedure_id' => $this->procedure('Laser')->id, 'sessions' => 3, 'unit_price' => '200']);
        $this->assertSame($before, $count());

        DB::table('role_permissions')->where('role', 'professional')->where('permission', Permission::PlansView->value)->delete();
        app(RolePermissions::class)->flush();
        $this->get($summaryUrl)->assertForbidden();
    }

    public function test_printed_quote_has_the_clinic_header_and_is_audited(): void
    {
        ClinicProfile::query()->forceCreate(['id' => ClinicProfile::SINGLETON_ID, 'legal_name' => 'Clínica Bella Ltda.']);
        $plan = $this->createPlan();

        $this->get($this->plansUrl()."/{$plan->id}/imprimir")
            ->assertOk()
            ->assertSee('Clínica Bella Ltda.')
            ->assertSee('Microagulhamento')
            ->assertSee('R$ 1.980,00')
            ->assertSee('Intervalo de 30 dias');

        $this->assertSame(1, AuditLog::where('action', AuditAction::TreatmentPlanPrinted->value)->count());
    }

    public function test_plans_list_keeps_a_constant_number_of_queries(): void
    {
        $this->createPlan();
        $this->actingAs(User::factory()->create());

        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->get('/orcamentos')->assertOk();

            return count(DB::getQueryLog());
        };

        $count();
        $before = $count();

        foreach (range(1, 4) as $index) {
            $patient = Patient::factory()->create();
            $plan = new TreatmentPlan(['valid_until' => '2026-10-30']);
            $plan->patient_id = $patient->id;
            $plan->status = TreatmentPlanStatus::Open;
            $plan->save();
            $plan->items()->create(['procedure_id' => $this->procedure("Procedimento {$index}")->id, 'sessions' => 2, 'unit_price' => '100']);
        }

        $this->assertSame($before, $count());
    }
}
