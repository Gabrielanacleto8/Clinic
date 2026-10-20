<?php

namespace Tests\Feature;

use App\Enums\AuditAction;
use App\Enums\ProductUnit;
use App\Enums\StockMovementType;
use App\Enums\UserRole;
use App\Exceptions\InsufficientStockException;
use App\Models\AuditLog;
use App\Models\Patient;
use App\Models\Procedure;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\StockMovement;
use App\Models\StockUnit;
use App\Models\Treatment;
use App\Models\User;
use App\Services\StockAlerts;
use App\Services\StockLedger;
use App\Services\StockReceiver;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Produto fracionado: cada frasco ou seringa aberta é controlada à parte, do
 * estoque ao atendimento, com sobra, descarte e custo do que se perdeu.
 */
class FractionedStockTest extends TestCase
{
    use RefreshDatabase;

    private User $professional;

    private User $admin;

    private Patient $patient;

    private Procedure $procedure;

    /** Toxina: frasco de 100 U, 24 h depois de aberto, sobra pode ir para outro paciente. */
    private ProductBatch $toxin;

    /** Preenchedor: seringa de 1 mL, uso em um só paciente. */
    private ProductBatch $filler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-08 13:00', 'America/Sao_Paulo'));

        $this->professional = User::factory()->professional()->create();
        $this->admin = User::factory()->admin()->create();
        $this->patient = Patient::factory()->create();
        $this->procedure = Procedure::factory()->create();

        $toxin = Product::factory()->create([
            'name' => 'Toxina botulínica', 'unit' => ProductUnit::ToxinUnit,
            'unit_content' => 100, 'hours_after_opening' => 24, 'multi_patient_use' => true,
        ]);
        $filler = Product::factory()->create([
            'name' => 'Ácido hialurônico', 'unit' => ProductUnit::Milliliter,
            'unit_content' => 1, 'hours_after_opening' => null, 'multi_patient_use' => false,
        ]);

        // Entradas pelo recebimento, com custo: R$ 12,00 por U e R$ 900,00 por mL.
        $receiver = app(StockReceiver::class);
        $this->toxin = $receiver->receive($toxin->id, ['batch_code' => 'TX01', 'quantity' => '300', 'expires_on' => '2027-06-30', 'unit_cost' => '12.00'], $this->admin)->batch;
        $this->filler = $receiver->receive($filler->id, ['batch_code' => 'AH01', 'quantity' => '3', 'expires_on' => '2027-06-30', 'unit_cost' => '900.00'], $this->admin)->batch;
    }

    /**
     * @param  list<array<string, mixed>>  $products
     * @return array<string, mixed>
     */
    private function payload(array $products): array
    {
        return [
            'performed_at' => '2026-10-08T12:00',
            'procedure_id' => $this->procedure->id,
            'description' => 'Aplicação.',
            'products' => $products,
            'amount' => '0',
            'first_installment_paid' => '0',
        ];
    }

    private function treat(array $products, ?User $user = null): TestResponse
    {
        return $this->actingAs($user ?? $this->professional)->post("/pacientes/{$this->patient->id}/atendimentos", $this->payload($products));
    }

    private function consumptions(): int
    {
        return StockMovement::where('type', StockMovementType::Consumption)->count();
    }

    public function test_a_treatment_opens_a_new_unit_and_consumes_from_it(): void
    {
        $this->treat([['product_batch_id' => $this->toxin->id, 'quantity' => '30']])->assertSessionHasNoErrors();

        $unit = StockUnit::sole();
        $treatment = Treatment::sole();

        $this->assertSame($this->toxin->id, $unit->product_batch_id);
        $this->assertSame('100.000', $unit->initial_quantity);
        $this->assertSame('70.000', $unit->quantity);
        $this->assertSame($this->professional->id, $unit->opened_by);
        $this->assertSame('270.000', $this->toxin->refresh()->quantity);

        // Rastreabilidade: o consumo aponta atendimento, lote e unidade, com o custo proporcional.
        $consumption = StockMovement::where('type', StockMovementType::Consumption)->sole();
        $this->assertSame($unit->id, $consumption->stock_unit_id);
        $this->assertSame($treatment->id, $consumption->treatment_id);
        $this->assertSame('-30.000', $consumption->quantity);
        $this->assertSame('12.00', $consumption->unit_cost);
        $this->assertSame('360.00', $consumption->value());

        $this->assertDatabaseHas('treatment_products', ['treatment_id' => $treatment->id, 'product_batch_id' => $this->toxin->id, 'stock_unit_id' => $unit->id, 'quantity' => '30.000']);
        $this->assertDatabaseHas('audit_logs', ['action' => AuditAction::StockUnitOpened->value, 'auditable_type' => 'stock_unit', 'auditable_id' => $unit->id]);

        // Na ficha do atendimento aparece a unidade usada.
        $this->get("/pacientes/{$this->patient->id}/atendimentos/{$treatment->id}")->assertOk()->assertSee('unidade nº '.$unit->id);
    }

    public function test_the_leftover_of_a_multi_patient_unit_is_used_in_another_treatment(): void
    {
        $this->treat([['product_batch_id' => $this->toxin->id, 'quantity' => '60']])->assertSessionHasNoErrors();
        $first = StockUnit::sole();

        // Outro paciente, horas depois: 40 U da sobra e 20 U de um frasco novo do mesmo lote.
        $this->travel(3)->hours();
        $this->patient = Patient::factory()->create();
        $this->treat([
            ['product_batch_id' => $this->toxin->id, 'stock_unit_id' => $first->id, 'quantity' => '40'],
            ['product_batch_id' => $this->toxin->id, 'quantity' => '20'],
        ])->assertSessionHasNoErrors();

        $this->assertSame('0.000', $first->refresh()->quantity);
        $this->assertSame('80.000', StockUnit::whereKeyNot($first->id)->sole()->quantity);
        $this->assertSame('180.000', $this->toxin->refresh()->quantity);
        $this->assertSame(3, $this->consumptions());
        $this->assertSame(0, StockMovement::where('type', StockMovementType::Loss)->count());
    }

    public function test_the_leftover_of_a_single_use_unit_is_discarded_with_its_cost(): void
    {
        $this->treat([['product_batch_id' => $this->filler->id, 'quantity' => '0,8']])->assertSessionHasNoErrors();

        $unit = StockUnit::sole();
        $treatment = Treatment::sole();
        $this->assertSame('0.000', $unit->quantity);
        $this->assertSame('2.000', $this->filler->refresh()->quantity);

        // A sobra vira perda ligada ao mesmo atendimento, com o valor desperdiçado.
        $loss = StockMovement::where('type', StockMovementType::Loss)->sole();
        $this->assertSame($unit->id, $loss->stock_unit_id);
        $this->assertSame($treatment->id, $loss->treatment_id);
        $this->assertSame('-0.200', $loss->quantity);
        $this->assertSame('180.00', $loss->value());

        // A unidade não volta para outro atendimento.
        $this->treat([['product_batch_id' => $this->filler->id, 'stock_unit_id' => $unit->id, 'quantity' => '0,1']])
            ->assertSessionHasErrors(['products' => 'Unidade nº '.$unit->id.' está esgotada.']);
        $this->assertSame(1, Treatment::count());

        // A perda aparece com o valor na aba Movimentações.
        $this->actingAs($this->admin)->get('/estoque/movimentacoes?tipo=loss')->assertOk()->assertSee('R$ 180,00');
    }

    public function test_a_unit_past_its_opening_limit_is_blocked_and_flagged_for_discard(): void
    {
        $unit = app(StockLedger::class)->openUnit($this->toxin, $this->admin);

        $this->travel(25)->hours();

        $this->treat([['product_batch_id' => $this->toxin->id, 'stock_unit_id' => $unit->id, 'quantity' => '10']])
            ->assertSessionHasErrors('products');
        $this->assertSame(0, Treatment::count());
        $this->assertSame('100.000', $unit->refresh()->quantity);

        // Alerta do estoque e filtro de lotes com unidade a descartar.
        $this->assertSame(1, app(StockAlerts::class)->summary()['units_to_discard']);
        $this->actingAs($this->admin)->get('/estoque/lotes?situacao=descartar')->assertOk()->assertSee('TX01');
        $this->actingAs($this->admin)->get("/estoque/unidades/{$unit->id}")->assertOk()->assertSee('passou do prazo de uso depois de aberta');
    }

    public function test_expired_batches_insufficient_balance_and_wrong_units_are_refused(): void
    {
        $unit = app(StockLedger::class)->openUnit($this->toxin, $this->admin);
        $needle = ProductBatch::factory()->create(['quantity' => 10]);

        // Mais do que resta na unidade.
        $this->treat([['product_batch_id' => $this->toxin->id, 'stock_unit_id' => $unit->id, 'quantity' => '120']])
            ->assertSessionHasErrors(['products' => 'Saldo insuficiente de Toxina botulínica, lote TX01 na unidade nº '.$unit->id.'. Disponível: 100, necessário: 120.']);

        // Unidade de outro lote, unidade em produto não fracionado e a mesma unidade duas vezes.
        $this->treat([['product_batch_id' => $this->filler->id, 'stock_unit_id' => $unit->id, 'quantity' => '1']])
            ->assertSessionHasErrors(['products.0.stock_unit_id' => 'Esta unidade não é do lote escolhido.']);
        $this->treat([['product_batch_id' => $needle->id, 'stock_unit_id' => $unit->id, 'quantity' => '1']])
            ->assertSessionHasErrors('products.0.stock_unit_id');
        $this->treat([
            ['product_batch_id' => $this->toxin->id, 'stock_unit_id' => $unit->id, 'quantity' => '10'],
            ['product_batch_id' => $this->toxin->id, 'stock_unit_id' => $unit->id, 'quantity' => '10'],
        ])->assertSessionHasErrors(['products.1.stock_unit_id' => 'Esta unidade está repetida na lista.']);

        // Lote vencido: nem unidade aberta, nem unidade nova.
        DB::table('product_batches')->where('id', $this->toxin->id)->update(['expires_on' => '2026-10-07']);
        $this->treat([['product_batch_id' => $this->toxin->id, 'stock_unit_id' => $unit->id, 'quantity' => '10']])->assertSessionHasErrors('products.0.product_batch_id');

        $this->assertSame(0, Treatment::count());
        $this->assertSame(0, $this->consumptions());
        $this->assertSame('100.000', $unit->refresh()->quantity);
        $this->assertSame(1, StockUnit::count());
    }

    public function test_a_failed_treatment_does_not_leave_a_unit_opened(): void
    {
        // Seringa de 1 mL não dá 1,5 mL: a abertura é desfeita junto com o atendimento.
        $this->treat([['product_batch_id' => $this->filler->id, 'quantity' => '1,5']])->assertSessionHasErrors('products');

        $this->assertSame(0, StockUnit::count());
        $this->assertSame('3.000', $this->filler->refresh()->quantity);
        $this->assertDatabaseMissing('audit_logs', ['action' => AuditAction::StockUnitOpened->value]);
    }

    public function test_batch_movements_never_take_what_is_inside_open_units(): void
    {
        $ledger = app(StockLedger::class);
        $ledger->openUnit($this->toxin, $this->admin);

        // 300 U no lote: 100 na unidade aberta e 200 lacradas.
        try {
            $ledger->lose($this->toxin, '250', 'Quebra', $this->admin);
            $this->fail('A perda não pode alcançar a unidade aberta.');
        } catch (InsufficientStockException $exception) {
            $this->assertSame('200.000', $exception->available);
        }

        $this->actingAs($this->admin)->post("/estoque/{$this->toxin->product_id}/movimentacoes", [
            'type' => StockMovementType::Adjustment->value, 'product_batch_id' => $this->toxin->id, 'quantity' => '50', 'reason' => 'Contagem',
        ])->assertSessionHasErrors('quantity');

        $this->actingAs($this->admin)->put('/estoque/inventario', ['reason' => 'Inventário', 'counted' => [$this->toxin->id => '90']])
            ->assertSessionHasErrors("counted.{$this->toxin->id}");

        $ledger->lose($this->toxin, '200', 'Quebra', $this->admin);
        $this->assertSame('100.000', $this->toxin->refresh()->quantity);

        // Sem frasco lacrado, não há o que abrir.
        $this->actingAs($this->admin)->post("/estoque/lotes/{$this->toxin->id}/unidades")
            ->assertSessionHasErrors(['unit' => 'Não há unidade lacrada de Toxina botulínica, lote TX01 para abrir. Lacrado: 0 U.']);
        $this->assertSame(1, StockUnit::count());
    }

    public function test_the_lock_reads_the_current_balance_and_not_a_stale_model(): void
    {
        $ledger = app(StockLedger::class);
        $unit = $ledger->openUnit($this->toxin, $this->admin);
        $stale = StockUnit::find($unit->id);

        // Outra pessoa usa 80 U da mesma unidade enquanto a tela ainda mostra 100 U.
        $ledger->consumeFromUnit($unit, '80', Treatment::factory()->create(), $this->professional);

        try {
            $ledger->consumeFromUnit($stale, '30', Treatment::factory()->create(), $this->professional);
            $this->fail('O saldo travado deveria ser o atual.');
        } catch (InsufficientStockException $exception) {
            $this->assertSame('20.000', $exception->available);
        }

        $this->assertSame('20.000', $unit->refresh()->quantity);
        $this->assertSame('220.000', $this->toxin->refresh()->quantity);
    }

    public function test_the_database_refuses_consuming_the_same_unit_twice_in_one_treatment(): void
    {
        $ledger = app(StockLedger::class);
        $unit = $ledger->openUnit($this->toxin, $this->admin);
        $treatment = Treatment::factory()->create();
        $ledger->consumeFromUnit($unit, '10', $treatment, $this->professional);

        try {
            $ledger->consumeFromUnit($unit, '10', $treatment, $this->professional);
            $this->fail('O banco deveria recusar o consumo em dobro.');
        } catch (UniqueConstraintViolationException) {
            // A segunda baixa é desfeita por inteiro.
        }

        $this->assertSame('90.000', $unit->refresh()->quantity);
        $this->assertSame('290.000', $this->toxin->refresh()->quantity);
    }

    public function test_stock_users_open_and_discard_units_with_a_reason(): void
    {
        $this->actingAs($this->admin)->post("/estoque/lotes/{$this->toxin->id}/unidades")->assertRedirect();
        $unit = StockUnit::sole();

        $this->actingAs($this->admin)->post("/estoque/unidades/{$unit->id}/descarte", ['reason' => ''])->assertSessionHasErrors('reason');

        $this->actingAs($this->admin)->post("/estoque/unidades/{$unit->id}/descarte", ['reason' => 'Contaminação'])
            ->assertRedirect("/estoque/unidades/{$unit->id}");

        $loss = StockMovement::where('type', StockMovementType::Loss)->sole();
        $this->assertSame('-100.000', $loss->quantity);
        $this->assertSame('1200.00', $loss->value());
        $this->assertSame('0.000', $unit->refresh()->quantity);
        $this->assertSame('200.000', $this->toxin->refresh()->quantity);

        $this->actingAs($this->admin)->get("/estoque/lotes/{$this->toxin->id}")->assertOk()->assertSee('R$ 1.200,00 desperdiçados', false);
    }

    public function test_access_to_units_follows_stock_permissions(): void
    {
        $unit = app(StockLedger::class)->openUnit($this->toxin, $this->admin);
        $receptionist = User::factory()->role(UserRole::Receptionist)->create();

        // O profissional vê o estoque, mas não abre nem descarta pelo estoque.
        $this->actingAs($this->professional)->get("/estoque/unidades/{$unit->id}")->assertOk();
        $this->actingAs($this->professional)->post("/estoque/lotes/{$this->toxin->id}/unidades")->assertForbidden();
        $this->actingAs($this->professional)->post("/estoque/unidades/{$unit->id}/descarte", ['reason' => 'x'])->assertForbidden();

        $this->actingAs($receptionist)->get("/estoque/unidades/{$unit->id}")->assertForbidden();

        $this->assertSame(1, StockUnit::count());
        $this->assertSame('100.000', $unit->refresh()->quantity);
    }

    public function test_fractioned_products_enter_in_whole_units(): void
    {
        $this->actingAs($this->admin)->post('/estoque/entradas', ['product_id' => $this->toxin->product_id, 'batch_code' => 'TX02', 'quantity' => '150'])
            ->assertSessionHasErrors('quantity');

        $this->actingAs($this->admin)->post('/estoque/entradas', ['product_id' => $this->toxin->product_id, 'batch_code' => 'TX02', 'quantity' => '200'])
            ->assertSessionHasNoErrors();
    }

    public function test_product_form_saves_fractioning_and_keeps_open_units_owned(): void
    {
        $this->actingAs($this->admin)->post('/estoque', [
            'name' => 'Bioestimulador', 'category' => 'injectable', 'unit' => ProductUnit::Milliliter->value, 'minimum_stock' => '0',
            'unit_content' => '5', 'hours_after_opening' => '72', 'multi_patient_use' => '1', 'is_active' => '1',
        ])->assertSessionHasNoErrors();

        $product = Product::where('name', 'Bioestimulador')->sole();
        $this->assertSame('5.000', $product->unit_content);
        $this->assertSame(72, $product->hours_after_opening);
        $this->assertTrue($product->multi_patient_use);

        // Com unidade aberta e saldo, o produto não deixa de ser fracionado.
        app(StockLedger::class)->openUnit($this->toxin, $this->admin);
        $toxin = $this->toxin->product;

        $this->actingAs($this->admin)->put("/estoque/{$toxin->id}", [
            'name' => $toxin->name, 'category' => $toxin->category->value, 'unit' => $toxin->unit->value, 'minimum_stock' => '0',
            'unit_content' => '', 'is_active' => '1',
        ])->assertSessionHasErrors('unit_content');
        $this->assertSame('100.000', $toxin->refresh()->unit_content);
    }

    public function test_the_treatment_form_lists_usable_open_units(): void
    {
        $unit = app(StockLedger::class)->openUnit($this->toxin, $this->admin);

        $this->actingAs($this->professional)->get("/pacientes/{$this->patient->id}/atendimentos/novo")
            ->assertOk()
            ->assertSee('name="products[0][stock_unit_id]"', false)
            ->assertSee('data-batch="'.$this->toxin->id.'"', false)
            ->assertSee('Nº '.$unit->id.' · Toxina botulínica, lote TX01');

        // Fora do prazo depois de aberta, a unidade sai da lista.
        $this->travel(25)->hours();
        $this->actingAs($this->professional)->get("/pacientes/{$this->patient->id}/atendimentos/novo")
            ->assertOk()
            ->assertDontSee('data-batch="'.$this->toxin->id.'"', false);
    }

    public function test_a_used_unit_does_not_reach_another_patient_after_the_product_becomes_single_use(): void
    {
        $this->treat([['product_batch_id' => $this->toxin->id, 'quantity' => '30']])->assertSessionHasNoErrors();
        $unit = StockUnit::sole();

        // O cadastro passa a dizer "uso em um só paciente" com o frasco já usado e aberto.
        DB::table('products')->where('id', $this->toxin->product_id)->update(['multi_patient_use' => false]);

        $this->patient = Patient::factory()->create();
        $this->treat([['product_batch_id' => $this->toxin->id, 'stock_unit_id' => $unit->id, 'quantity' => '10']])
            ->assertSessionHasErrors(['products' => 'Unidade nº '.$unit->id.' já foi usada em um atendimento e o produto é de uso em um só paciente.']);

        $this->assertSame(1, Treatment::count());
        $this->assertSame('70.000', $unit->refresh()->quantity);

        // Sai da lista do formulário e entra nos alertas para descarte.
        $this->actingAs($this->professional)->get("/pacientes/{$this->patient->id}/atendimentos/novo")
            ->assertOk()
            ->assertDontSee('data-batch="'.$this->toxin->id.'"', false);
        $this->assertSame(1, app(StockAlerts::class)->summary()['units_to_discard']);
    }

    public function test_the_unit_history_hides_the_procedure_from_users_without_record_access(): void
    {
        $this->procedure->update(['name' => 'Toxina na glabela']);
        $this->treat([['product_batch_id' => $this->toxin->id, 'quantity' => '30']])->assertSessionHasNoErrors();
        $unit = StockUnit::sole();

        $this->actingAs($this->admin)->get("/estoque/unidades/{$unit->id}")->assertOk()->assertSee('Toxina na glabela');

        // Perfil com acesso ao estoque e sem acesso ao prontuário.
        $stockClerk = User::factory()->role(UserRole::Receptionist)->create();
        DB::table('role_permissions')->insert(['role' => UserRole::Receptionist->value, 'permission' => 'stock.view']);
        // As permissões são lidas uma vez por requisição; o teste reaproveita a instância.
        $this->app->forgetScopedInstances();

        $this->actingAs($stockClerk)->get("/estoque/unidades/{$unit->id}")
            ->assertOk()
            ->assertSee('Atendimento nº')
            ->assertDontSee('Toxina na glabela');
    }

    /**
     * Consultas de uma requisição GET. A primeira requisição do processo faz
     * uma consulta a mais, que não se repete: quem compara faz uma de aquecimento.
     */
    private function countQueries(string $url, ?User $user = null): int
    {
        $this->actingAs($user ?? $this->admin);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get($url)->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    }

    private function grant(UserRole $role, string ...$permissions): void
    {
        DB::table('role_permissions')->insert(array_map(fn (string $permission) => ['role' => $role->value, 'permission' => $permission], $permissions));
        // As permissões são lidas uma vez por requisição; o teste reaproveita a instância.
        $this->app->forgetScopedInstances();
    }

    public function test_guests_are_sent_to_login_from_unit_routes(): void
    {
        $unit = app(StockLedger::class)->openUnit($this->toxin, $this->admin);

        $this->get("/estoque/unidades/{$unit->id}")->assertRedirect('/login');
        $this->post("/estoque/lotes/{$this->toxin->id}/unidades")->assertRedirect('/login');
        $this->post("/estoque/unidades/{$unit->id}/descarte", ['reason' => 'Contaminação'])->assertRedirect('/login');

        $this->assertSame(1, StockUnit::count());
        $this->assertSame('100.000', $unit->refresh()->quantity);
        $this->assertSame(0, StockMovement::where('type', StockMovementType::Loss)->count());
    }

    public function test_opening_and_discarding_follow_the_stock_manage_permission_and_not_the_role(): void
    {
        $receptionist = User::factory()->role(UserRole::Receptionist)->create();
        $unit = app(StockLedger::class)->openUnit($this->toxin, $this->admin);

        // Sem nenhuma permissão de estoque: nem abre nem descarta.
        $this->actingAs($receptionist)->post("/estoque/lotes/{$this->toxin->id}/unidades")->assertForbidden();
        $this->actingAs($receptionist)->post("/estoque/unidades/{$unit->id}/descarte", ['reason' => 'Contaminação'])->assertForbidden();
        $this->assertSame(1, StockUnit::count());
        $this->assertSame('100.000', $unit->refresh()->quantity);

        // Só ver o estoque não basta para movimentar.
        $this->grant(UserRole::Receptionist, 'stock.view');
        $this->actingAs($receptionist)->post("/estoque/lotes/{$this->toxin->id}/unidades")->assertForbidden();
        $this->actingAs($receptionist)->post("/estoque/unidades/{$unit->id}/descarte", ['reason' => 'Contaminação'])->assertForbidden();
        $this->assertSame(1, StockUnit::count());

        // Com a permissão de movimentar, o perfil abre e descarta.
        $this->grant(UserRole::Receptionist, 'stock.manage');
        $this->actingAs($receptionist)->post("/estoque/lotes/{$this->toxin->id}/unidades")->assertRedirect();
        $opened = StockUnit::whereKeyNot($unit->id)->sole();
        $this->assertSame($receptionist->id, $opened->opened_by);

        $this->actingAs($receptionist)->post("/estoque/unidades/{$unit->id}/descarte", ['reason' => 'Contaminação'])
            ->assertRedirect("/estoque/unidades/{$unit->id}");
        $this->assertDatabaseHas('stock_movements', ['stock_unit_id' => $unit->id, 'type' => StockMovementType::Loss->value, 'quantity' => '-100.000', 'user_id' => $receptionist->id]);
        $this->assertSame('0.000', $unit->refresh()->quantity);
    }

    public function test_units_are_only_opened_from_usable_fractioned_batches(): void
    {
        $needle = ProductBatch::factory()->create(['quantity' => 10]);

        // Produto não fracionado não tem unidade física.
        $this->actingAs($this->admin)->post("/estoque/lotes/{$needle->id}/unidades")->assertNotFound();
        $this->actingAs($this->admin)->get('/estoque/unidades/999999')->assertNotFound();

        // Lote vencido.
        DB::table('product_batches')->where('id', $this->toxin->id)->update(['expires_on' => '2026-10-07']);
        $this->actingAs($this->admin)->post("/estoque/lotes/{$this->toxin->id}/unidades")
            ->assertSessionHasErrors(['unit' => 'O lote de Toxina botulínica, lote TX01 está vencido. Não abra unidades dele.']);

        // Produto inativo.
        DB::table('products')->where('id', $this->filler->product_id)->update(['is_active' => false]);
        $this->actingAs($this->admin)->post("/estoque/lotes/{$this->filler->id}/unidades")
            ->assertSessionHasErrors(['unit' => 'O produto Ácido hialurônico está inativo.']);

        $this->assertSame(0, StockUnit::count());
        $this->assertDatabaseMissing('audit_logs', ['action' => AuditAction::StockUnitOpened->value]);
    }

    public function test_discard_validates_the_reason_and_ignores_an_empty_unit(): void
    {
        $unit = app(StockLedger::class)->openUnit($this->toxin, $this->admin);

        $this->actingAs($this->admin)->post("/estoque/unidades/{$unit->id}/descarte", ['reason' => str_repeat('a', 256)])
            ->assertSessionHasErrors('reason');
        $this->actingAs($this->admin)->post("/estoque/unidades/{$unit->id}/descarte", [])
            ->assertSessionHasErrors(['reason' => 'Informe o motivo do descarte.']);
        $this->assertSame('100.000', $unit->refresh()->quantity);

        $this->actingAs($this->admin)->post("/estoque/unidades/{$unit->id}/descarte", ['reason' => 'Contaminação'])->assertSessionHasNoErrors();

        // Descartar de novo a unidade vazia não gera outra perda.
        $this->actingAs($this->admin)->post("/estoque/unidades/{$unit->id}/descarte", ['reason' => 'Contaminação'])
            ->assertRedirect("/estoque/unidades/{$unit->id}")
            ->assertSessionHas('success', 'A unidade já estava vazia. Nada foi alterado.');

        $this->assertSame(1, StockMovement::where('type', StockMovementType::Loss)->count());
        $this->assertSame('200.000', $this->toxin->refresh()->quantity);
    }

    public function test_unit_opening_and_discard_are_audited_without_the_reason(): void
    {
        $this->actingAs($this->admin)->post("/estoque/lotes/{$this->toxin->id}/unidades")->assertRedirect();
        $unit = StockUnit::sole();
        $this->actingAs($this->admin)->post("/estoque/unidades/{$unit->id}/descarte", ['reason' => 'Frasco derrubado pela Maria'])->assertSessionHasNoErrors();

        $opened = AuditLog::where('action', AuditAction::StockUnitOpened->value)->sole();
        $this->assertSame('stock_unit', $opened->auditable_type);
        $this->assertSame($unit->id, $opened->auditable_id);
        $this->assertSame($this->admin->id, $opened->user_id);
        $this->assertEquals(['product_id' => $this->toxin->product_id, 'product_batch_id' => $this->toxin->id], $opened->metadata);

        $loss = StockMovement::where('type', StockMovementType::Loss)->sole();
        $discarded = AuditLog::where('action', AuditAction::StockMoved->value)->where('auditable_id', $loss->id)->sole();
        $this->assertEquals([
            'type' => StockMovementType::Loss->value, 'product_id' => $this->toxin->product_id,
            'product_batch_id' => $this->toxin->id, 'stock_unit_id' => $unit->id,
        ], $discarded->metadata);

        // Na auditoria entram identificadores, nunca o texto digitado.
        $this->assertStringNotContainsString('Maria', AuditLog::all()->toJson());
    }

    public function test_the_batch_filter_lists_only_batches_with_open_units_to_discard(): void
    {
        $ledger = app(StockLedger::class);
        $receiver = app(StockReceiver::class);

        // Fora do prazo depois de aberta.
        $ledger->openUnit($this->toxin, $this->admin);
        // Aberta e ainda usável: não entra.
        $ledger->openUnit($this->filler, $this->admin);

        // Lote que venceu com unidade aberta.
        $expiring = $receiver->receive($this->toxin->product_id, ['batch_code' => 'TXVENC', 'quantity' => '100', 'expires_on' => '2026-10-09', 'unit_cost' => '12.00'], $this->admin)->batch;
        $ledger->openUnit($expiring, $this->admin);

        // Produto inativado com unidade aberta.
        $inactive = Product::factory()->create(['name' => 'Bioestimulador', 'unit' => ProductUnit::Milliliter, 'unit_content' => 5, 'hours_after_opening' => null, 'multi_patient_use' => true]);
        $inactiveBatch = $receiver->receive($inactive->id, ['batch_code' => 'BIOINAT', 'quantity' => '10', 'expires_on' => '2027-06-30', 'unit_cost' => '50.00'], $this->admin)->batch;
        $ledger->openUnit($inactiveBatch, $this->admin);

        // Já descartada: sem saldo, não há o que descartar.
        $emptied = $receiver->receive($this->toxin->product_id, ['batch_code' => 'TXVAZIO', 'quantity' => '100', 'expires_on' => '2027-06-30', 'unit_cost' => '12.00'], $this->admin)->batch;
        $ledger->discard($ledger->openUnit($emptied, $this->admin), 'Contaminação', $this->admin);

        $this->travel(25)->hours();
        DB::table('products')->where('id', $inactive->id)->update(['is_active' => false]);

        $this->assertSame(3, app(StockAlerts::class)->summary()['units_to_discard']);

        $this->actingAs($this->admin)->get('/estoque/lotes?situacao=descartar')
            ->assertOk()
            ->assertSee('3 lotes')
            ->assertSee('TX01')
            ->assertSee('TXVENC')
            ->assertSee('BIOINAT')
            ->assertDontSee('AH01')
            ->assertDontSee('TXVAZIO')
            // O alerta leva para este mesmo filtro.
            ->assertSee('3 unidades abertas a descartar')
            ->assertSee(route('stock.batches.index', ['situacao' => 'descartar']), false);

        // Descartadas as unidades, o filtro fica vazio.
        StockUnit::withBalance()->get()->each(fn (StockUnit $unit) => $ledger->discard($unit, 'Prazo', $this->admin));
        $this->actingAs($this->admin)->get('/estoque/lotes?situacao=descartar')->assertOk()->assertSee('Nenhum lote encontrado.');
        $this->assertSame(0, app(StockAlerts::class)->summary()['units_to_discard']);
    }

    public function test_the_batch_filter_is_denied_without_stock_access(): void
    {
        $this->get('/estoque/lotes?situacao=descartar')->assertRedirect('/login');
        $this->actingAs(User::factory()->role(UserRole::Receptionist)->create())->get('/estoque/lotes?situacao=descartar')->assertForbidden();
        $this->actingAs($this->professional)->get('/estoque/lotes?situacao=descartar')->assertOk();
    }

    public function test_the_movements_tab_totals_losses_within_the_filters(): void
    {
        $ledger = app(StockLedger::class);
        // R$ 1.200,00 de toxina e R$ 900,00 de preenchedor.
        $ledger->discard($ledger->openUnit($this->toxin, $this->admin), 'Contaminação', $this->admin);
        $ledger->discard($ledger->openUnit($this->filler, $this->admin), 'Contaminação', $this->admin);

        $this->actingAs($this->admin)->get('/estoque/movimentacoes')->assertOk()->assertSee('R$ 2.100,00');
        $this->actingAs($this->admin)->get('/estoque/movimentacoes?q=TX01')->assertOk()->assertSee('R$ 1.200,00')->assertDontSee('R$ 2.100,00');
        // Sem perda no filtro, sem total.
        $this->actingAs($this->admin)->get('/estoque/movimentacoes?tipo=entry')->assertOk()->assertDontSee('Perdas e descartes nestes filtros');
    }

    public function test_inventory_accepts_a_count_that_keeps_the_open_units(): void
    {
        $unit = app(StockLedger::class)->openUnit($this->toxin, $this->admin);

        $this->actingAs($this->admin)->put('/estoque/inventario', ['reason' => 'Inventário', 'counted' => [$this->toxin->id => '100']])
            ->assertSessionHasNoErrors();

        $this->assertSame('100.000', $this->toxin->refresh()->quantity);
        $this->assertSame('100.000', $unit->refresh()->quantity);
        $this->assertDatabaseHas('stock_movements', ['product_batch_id' => $this->toxin->id, 'type' => StockMovementType::Adjustment->value, 'quantity' => '-200.000', 'stock_unit_id' => null]);
    }

    public function test_product_form_validates_the_fractioning_fields(): void
    {
        $base = ['name' => 'Bioestimulador', 'category' => 'injectable', 'unit' => ProductUnit::Milliliter->value, 'minimum_stock' => '0', 'is_active' => '1'];

        $this->actingAs($this->admin)->post('/estoque', [...$base, 'unit_content' => '0', 'hours_after_opening' => '0'])
            ->assertSessionHasErrors(['unit_content', 'hours_after_opening']);
        $this->actingAs($this->admin)->post('/estoque', [...$base, 'unit_content' => 'abc', 'hours_after_opening' => '8761'])
            ->assertSessionHasErrors(['unit_content', 'hours_after_opening']);
        $this->assertSame(0, Product::where('name', 'Bioestimulador')->count());

        // Número no padrão brasileiro.
        $this->actingAs($this->admin)->post('/estoque', [...$base, 'unit_content' => '2,5', 'hours_after_opening' => '48'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('products', ['name' => 'Bioestimulador', 'unit_content' => '2.500', 'hours_after_opening' => 48, 'multi_patient_use' => false]);

        // Sem conteúdo por unidade, prazo e uso em vários pacientes são descartados.
        $this->actingAs($this->admin)->post('/estoque', [...$base, 'name' => 'Gaze', 'unit' => 'un', 'unit_content' => '', 'hours_after_opening' => '48', 'multi_patient_use' => '1'])
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('products', ['name' => 'Gaze', 'unit_content' => null, 'hours_after_opening' => null, 'multi_patient_use' => false]);
    }

    public function test_product_fractioning_is_denied_without_stock_manage(): void
    {
        $toxin = $this->toxin->product;
        $data = ['name' => $toxin->name, 'category' => $toxin->category->value, 'unit' => $toxin->unit->value, 'minimum_stock' => '0', 'unit_content' => '50', 'is_active' => '1'];

        $this->put("/estoque/{$toxin->id}", $data)->assertRedirect('/login');
        $this->actingAs($this->professional)->put("/estoque/{$toxin->id}", $data)->assertForbidden();

        $this->assertSame('100.000', $toxin->refresh()->unit_content);
    }

    public function test_treatment_refuses_a_unit_that_no_longer_exists_or_of_an_inactive_product(): void
    {
        $unit = app(StockLedger::class)->openUnit($this->toxin, $this->admin);

        $this->treat([['product_batch_id' => $this->toxin->id, 'stock_unit_id' => 999999, 'quantity' => '10']])
            ->assertSessionHasErrors(['products.0.stock_unit_id' => 'Esta unidade não existe mais. Recarregue a página.']);

        DB::table('products')->where('id', $this->toxin->product_id)->update(['is_active' => false]);
        $this->treat([['product_batch_id' => $this->toxin->id, 'stock_unit_id' => $unit->id, 'quantity' => '10']])
            ->assertSessionHasErrors('products.0.product_batch_id');

        $this->assertSame(0, Treatment::count());
        $this->assertSame(0, $this->consumptions());
        $this->assertSame('100.000', $unit->refresh()->quantity);
    }

    public function test_treatment_audit_points_the_unit_without_patient_data(): void
    {
        $this->treat([['product_batch_id' => $this->filler->id, 'quantity' => '0,8']])->assertSessionHasNoErrors();
        $unit = StockUnit::sole();
        $treatment = Treatment::sole();

        $logs = AuditLog::where('action', AuditAction::StockMoved->value)->where('metadata->stock_unit_id', $unit->id)->orderBy('id')->get();

        // Consumo e descarte da sobra, ambos ligados ao atendimento e à unidade.
        $this->assertSame([StockMovementType::Consumption->value, StockMovementType::Loss->value], $logs->pluck('metadata.type')->all());
        $this->assertSame([$treatment->id, $treatment->id], $logs->pluck('metadata.treatment_id')->all());
        $this->assertStringNotContainsString($this->patient->name, $logs->toJson());
    }

    public function test_batch_page_with_open_units_runs_a_fixed_number_of_queries(): void
    {
        $ledger = app(StockLedger::class);
        $this->treat([['product_batch_id' => $this->toxin->id, 'quantity' => '30']])->assertSessionHasNoErrors();
        $url = "/estoque/lotes/{$this->toxin->id}";

        $this->countQueries($url);
        $withOne = $this->countQueries($url);

        // Mais unidades, abertas por outras pessoas, usadas por outros profissionais e descartadas.
        foreach (range(1, 2) as $index) {
            $unit = $ledger->openUnit($this->toxin, User::factory()->admin()->create());
            $this->patient = Patient::factory()->create();
            $this->treat([['product_batch_id' => $this->toxin->id, 'stock_unit_id' => $unit->id, 'quantity' => '10']], User::factory()->professional()->create())
                ->assertSessionHasNoErrors();
        }
        $ledger->discard(StockUnit::latest('id')->first(), 'Contaminação', $this->admin);
        $this->assertSame(2, StockUnit::withBalance()->count());

        $this->assertSame($withOne, $this->countQueries($url));
    }

    public function test_unit_page_runs_a_fixed_number_of_queries(): void
    {
        $this->treat([['product_batch_id' => $this->toxin->id, 'quantity' => '10']])->assertSessionHasNoErrors();
        $unit = StockUnit::sole();
        $url = "/estoque/unidades/{$unit->id}";

        $this->countQueries($url);
        $withOne = $this->countQueries($url);

        // Outros pacientes, profissionais e procedimentos usando a mesma unidade.
        foreach (range(1, 4) as $index) {
            $this->patient = Patient::factory()->create();
            $this->procedure = Procedure::factory()->create();
            $this->treat([['product_batch_id' => $this->toxin->id, 'stock_unit_id' => $unit->id, 'quantity' => '10']], User::factory()->professional()->create())
                ->assertSessionHasNoErrors();
        }
        app(StockLedger::class)->discard($unit, 'Contaminação', $this->admin);
        $this->assertSame(6, StockMovement::where('stock_unit_id', $unit->id)->count());

        $this->assertSame($withOne, $this->countQueries($url));
        // Quem não vê o prontuário cai no mesmo número de consultas.
        $this->grant(UserRole::Receptionist, 'stock.view');
        $clerk = User::factory()->role(UserRole::Receptionist)->create();
        $this->countQueries($url, $clerk);
        $this->assertLessThanOrEqual($withOne, $this->countQueries($url, $clerk));
    }

    public function test_treatment_form_with_open_units_runs_a_fixed_number_of_queries(): void
    {
        $ledger = app(StockLedger::class);
        $ledger->openUnit($this->toxin, $this->admin);
        $url = "/pacientes/{$this->patient->id}/atendimentos/novo";

        $this->countQueries($url, $this->professional);
        $withOne = $this->countQueries($url, $this->professional);

        // Mais unidades, em lotes e produtos diferentes, abertas por pessoas diferentes.
        $ledger->openUnit($this->toxin, User::factory()->admin()->create());
        $ledger->openUnit($this->filler, User::factory()->admin()->create());
        $other = Product::factory()->create(['name' => 'Bioestimulador', 'unit' => ProductUnit::Milliliter, 'unit_content' => 5, 'hours_after_opening' => 72, 'multi_patient_use' => true]);
        $otherBatch = app(StockReceiver::class)->receive($other->id, ['batch_code' => 'BIO1', 'quantity' => '15', 'expires_on' => '2027-06-30', 'unit_cost' => '50.00'], $this->admin)->batch;
        $ledger->openUnit($otherBatch, $this->admin);
        $ledger->openUnit($otherBatch, $this->admin);
        $this->assertSame(5, StockUnit::count());

        $this->assertSame($withOne, $this->countQueries($url, $this->professional));
    }

    public function test_movements_tab_with_units_and_losses_runs_a_fixed_number_of_queries(): void
    {
        // Um consumo de unidade e um descarte de sobra já na linha de base.
        $this->treat([['product_batch_id' => $this->filler->id, 'quantity' => '0,5']])->assertSessionHasNoErrors();

        $this->countQueries('/estoque/movimentacoes');
        $withOne = $this->countQueries('/estoque/movimentacoes');
        $this->countQueries('/estoque/movimentacoes?tipo=loss');
        $lossesWithOne = $this->countQueries('/estoque/movimentacoes?tipo=loss');

        // Consumos de unidades e descartes de sobra, por outros profissionais, em outros produtos.
        foreach (range(1, 2) as $index) {
            $this->patient = Patient::factory()->create();
            $this->treat([
                ['product_batch_id' => $this->filler->id, 'quantity' => '0,5'],
                ['product_batch_id' => $this->toxin->id, 'quantity' => '10'],
            ], User::factory()->professional()->create())->assertSessionHasNoErrors();
        }
        app(StockLedger::class)->discard(StockUnit::withBalance()->first(), 'Contaminação', $this->admin);
        $this->assertSame(4, StockMovement::where('type', StockMovementType::Loss)->count());

        $this->assertSame($withOne, $this->countQueries('/estoque/movimentacoes'));
        $this->assertSame($lossesWithOne, $this->countQueries('/estoque/movimentacoes?tipo=loss'));
    }
}
