<?php

namespace Tests\Feature;

use App\Enums\AuditAction;
use App\Enums\Permission;
use App\Enums\ProductUnit;
use App\Enums\StockMovementType;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\StockMovement;
use App\Models\Treatment;
use App\Models\User;
use App\Services\RolePermissions;
use App\Services\StockLedger;
use App\Services\StockReceiver;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Refinamentos do estoque: inventário que não desfaz movimentações feitas
 * enquanto se conta, unidades abertas com descarte na linha, totais das
 * movimentações, correção de custo e de código de lote, baixa de lote
 * vencido e o saldo disponível sem o que aguarda descarte.
 */
class StockRefinementsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-08 13:00', 'America/Sao_Paulo'));
        $this->admin = User::factory()->admin()->create(['name' => 'Ana Admin']);
    }

    private function receive(Product $product, string $code, string $quantity, ?string $expiresOn = '2027-06-30', ?string $unitCost = null): ProductBatch
    {
        return app(StockReceiver::class)->receive($product->id, ['batch_code' => $code, 'quantity' => $quantity, 'expires_on' => $expiresOn, 'unit_cost' => $unitCost], $this->admin)->batch;
    }

    private function toxin(array $attributes = []): Product
    {
        return Product::factory()->create(['name' => 'Toxina', 'unit' => ProductUnit::ToxinUnit, 'unit_content' => 100, 'multi_patient_use' => true, 'hours_after_opening' => 24, ...$attributes]);
    }

    /**
     * Lote vencido com saldo, criado como os lotes antigos: a entrada não aceita mais lote vencido.
     */
    private function expiredBatch(Product $product, string $code, string $quantity): ProductBatch
    {
        $batch = $this->receive($product, $code, $quantity, '2026-12-31', '10.00');
        DB::table('product_batches')->where('id', $batch->id)->update(['expires_on' => '2026-10-01']);

        return $batch->refresh();
    }

    // --- Inventário --------------------------------------------------------

    public function test_inventory_refuses_a_count_made_on_a_balance_that_changed_meanwhile(): void
    {
        $gauze = $this->receive(Product::factory()->create(['name' => 'Gaze']), 'GZ1', '10');
        $needle = $this->receive(Product::factory()->create(['name' => 'Agulha']), 'AG1', '20');

        $this->actingAs($this->admin);
        $this->get('/estoque/inventario')->assertOk()
            ->assertSee('name="expected['.$gauze->id.']" value="10"', false)
            ->assertSee('data-warn-unsaved', false)->assertSee('data-keepalive', false);

        // Entre abrir a tela e enviar, um atendimento consome 1 gaze.
        app(StockLedger::class)->consume($gauze, '1', Treatment::factory()->create(), $this->admin);

        $this->put('/estoque/inventario', [
            'reason' => 'Inventário mensal',
            'counted' => [$gauze->id => '10', $needle->id => '18'],
            'expected' => [$gauze->id => '10', $needle->id => '20'],
        ])->assertSessionHasErrors("counted.{$gauze->id}")->assertSessionHas('warning');

        // A gaze não foi "corrigida" de volta para 10: o consumo continua valendo. A agulha foi ajustada.
        $this->assertSame('9.000', $gauze->refresh()->quantity);
        $this->assertSame('18.000', $needle->refresh()->quantity);
        $this->assertStringContainsString('teve movimentação depois que a tela foi aberta', session('errors')->first("counted.{$gauze->id}"));

        // A conferência fica registrada só com o lote que foi de fato contado.
        $log = AuditLog::where('action', AuditAction::StockCounted->value)->sole();
        $this->assertSame([$needle->id], $log->metadata['batch_ids']);
        $this->assertSame([$needle->id], $log->metadata['adjusted_batch_ids']);
    }

    public function test_inventory_records_matching_counts_and_marks_counted_batches(): void
    {
        $gauze = $this->receive(Product::factory()->create(['name' => 'Gaze']), 'GZ1', '10');

        $this->actingAs($this->admin)->put('/estoque/inventario', [
            'reason' => 'Inventário', 'counted' => [$gauze->id => '10'], 'expected' => [$gauze->id => '10'],
        ])->assertSessionHas('success', 'Inventário conferido: todas as contagens batem com o saldo.');

        // Sem divergência não há movimentação, mas a conferência fica registrada.
        $this->assertSame(0, StockMovement::where('type', StockMovementType::Adjustment->value)->count());
        $this->assertSame([$gauze->id], AuditLog::where('action', AuditAction::StockCounted->value)->sole()->metadata['batch_ids']);

        $this->get('/estoque/inventario')->assertSee('Conferido hoje');
        $this->get("/estoque/lotes/{$gauze->id}")->assertSee('Última conferência de inventário em 08/10/2026')->assertSee('Ana Admin')->assertSee('(saldo conferia)');

        // No dia seguinte o selo some.
        $this->travel(1)->days();
        $this->get('/estoque/inventario')->assertDontSee('Conferido hoje');
    }

    public function test_fractioned_products_are_counted_in_closed_units(): void
    {
        $batch = $this->receive($this->toxin(), 'TX1', '300');
        app(StockLedger::class)->openUnit($batch, $this->admin);

        $this->actingAs($this->admin);
        $this->get('/estoque/inventario')->assertOk()->assertSee('2 unidades fechadas')->assertSee('= 200 U')->assertSee('data-unit-content="100"', false);

        // Unidade fechada contada em fração é recusada, com o que foi digitado de volta no campo.
        $this->put('/estoque/inventario', ['reason' => 'Inv', 'counted' => [$batch->id => '1,5'], 'in_units' => [$batch->id => '1'], 'expected' => [$batch->id => '200']])
            ->assertSessionHasErrors(["counted.{$batch->id}" => 'Informe um número inteiro de unidades fechadas (cada uma tem 100 U).'])
            ->assertSessionHasInput("counted.{$batch->id}", '1,5');

        // Uma unidade fechada encontrada: o lote fica com 100 fechados + 100 na unidade aberta.
        $this->put('/estoque/inventario', ['reason' => 'Inv', 'counted' => [$batch->id => '1'], 'in_units' => [$batch->id => '1'], 'expected' => [$batch->id => '200']])
            ->assertSessionHasNoErrors();
        $this->assertSame('200.000', $batch->refresh()->quantity);
        $this->assertDatabaseHas('stock_movements', ['product_batch_id' => $batch->id, 'type' => 'adjustment', 'quantity' => '-100.000']);
    }

    public function test_the_adjustment_dialog_also_refuses_a_stale_count(): void
    {
        $product = Product::factory()->create(['name' => 'Gaze']);
        $batch = $this->receive($product, 'GZ1', '10');
        app(StockLedger::class)->consume($batch, '2', Treatment::factory()->create(), $this->admin);

        $this->actingAs($this->admin)->post("/estoque/{$product->id}/movimentacoes", [
            'type' => 'adjustment', 'product_batch_id' => $batch->id, 'quantity' => '10', 'expected' => '10', 'reason' => 'Contagem',
        ])->assertSessionHasErrors('quantity');

        $this->assertSame('8.000', $batch->refresh()->quantity);

        $this->post("/estoque/{$product->id}/movimentacoes", [
            'type' => 'adjustment', 'product_batch_id' => $batch->id, 'quantity' => '7', 'expected' => '8', 'reason' => 'Contagem',
        ])->assertSessionHasNoErrors();

        $this->assertSame('7.000', $batch->refresh()->quantity);
    }

    public function test_exit_and_loss_of_fractioned_products_are_typed_in_closed_units(): void
    {
        $product = $this->toxin();
        $batch = $this->receive($product, 'TX1', '300');

        $this->actingAs($this->admin);
        $this->get("/estoque/lotes/{$batch->id}")->assertSee('Unidades fechadas perdidas')->assertSee('name="quantity_in_units" value="1"', false);

        $this->post("/estoque/{$product->id}/movimentacoes", ['type' => 'loss', 'product_batch_id' => $batch->id, 'quantity' => '1', 'quantity_in_units' => '1', 'reason' => 'Quebra'])
            ->assertSessionHasNoErrors();
        $this->assertSame('200.000', $batch->refresh()->quantity);

        $this->post("/estoque/{$product->id}/movimentacoes", ['type' => 'exit', 'product_batch_id' => $batch->id, 'quantity' => '0,5', 'quantity_in_units' => '1', 'reason' => 'Uso'])
            ->assertSessionHasErrors(['quantity' => 'Informe um número inteiro de unidades fechadas (cada uma tem 100 U).']);
    }

    // --- Unidades abertas --------------------------------------------------

    public function test_open_units_list_puts_units_to_discard_first_with_the_reason_filled_in(): void
    {
        $ledger = app(StockLedger::class);
        $product = $this->toxin();
        $batch = $this->receive($product, 'TX1', '300');
        $old = $ledger->openUnit($batch, $this->admin);
        $this->travel(30)->hours();
        $fresh = $ledger->openUnit($batch, $this->admin);

        $this->get('/estoque/unidades')->assertRedirect('/login');
        $this->actingAs(User::factory()->create())->get('/estoque/unidades')->assertForbidden();

        $response = $this->actingAs(User::factory()->professional()->create())->get('/estoque/unidades')->assertOk()
            ->assertSeeInOrder(['Para descartar', $old->label(), 'Em uso', $fresh->label()])
            ->assertSee('value="Unidade passou do prazo de uso depois de aberta', false)
            ->assertSee('Descartar</button>', false);

        // O alerta do topo leva para esta lista.
        $response->assertSee('1 unidade aberta a descartar')->assertSee('href="'.route('stock.units.index').'"', false);

        // Descarte pela linha volta para a lista.
        $this->from('/estoque/unidades')->post("/estoque/unidades/{$old->id}/descarte", ['reason' => 'Prazo vencido', '_unit' => $old->id])
            ->assertRedirect('/estoque/unidades')->assertSessionHas('success', 'Descarte registrado: '.$old->label().'.');
        $this->assertSame('0.000', $old->refresh()->quantity);

        // Filtro por produto, usado pelo link da página do produto.
        $this->actingAs($this->admin)->get("/estoque/{$product->id}")->assertSee('1 unidade aberta')->assertSee(e(route('stock.units.index', ['produto' => $product->id])), false);
        $this->get('/estoque/unidades?produto='.$product->id)->assertOk()->assertSee($fresh->label());
    }

    public function test_discarding_needs_only_the_discard_permission(): void
    {
        $batch = $this->receive($this->toxin(), 'TX1', '300');
        $unit = app(StockLedger::class)->openUnit($batch, $this->admin);

        app(RolePermissions::class)->sync(UserRole::Receptionist, [Permission::StockDiscard]);
        $this->actingAs(User::factory()->create())->post("/estoque/unidades/{$unit->id}/descarte", ['reason' => 'Contaminação'])->assertSessionHasNoErrors();
        $this->assertSame('0.000', $unit->refresh()->quantity);

        // Sem descarte nem gestão do estoque, não descarta.
        $other = app(StockLedger::class)->openUnit($batch, $this->admin);
        app(RolePermissions::class)->sync(UserRole::Receptionist, [Permission::StockView]);
        $this->actingAs(User::factory()->create())->post("/estoque/unidades/{$other->id}/descarte", ['reason' => 'x'])->assertForbidden();
    }

    public function test_open_units_list_runs_a_fixed_number_of_queries(): void
    {
        $ledger = app(StockLedger::class);
        $this->actingAs($this->admin);

        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->get('/estoque/unidades')->assertOk();
            $total = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $total;
        };

        $batch = $this->receive($this->toxin(), 'TX1', '1000');
        $ledger->openUnit($batch, $this->admin);
        $one = $count();

        foreach (range(2, 8) as $index) {
            $other = $this->receive($this->toxin(['name' => "Toxina {$index}"]), "TX{$index}", '200');
            $ledger->openUnit($other, $this->admin);
            $ledger->openUnit($batch, $this->admin);
        }

        $this->assertSame($one, $count());
    }

    // --- Movimentações -----------------------------------------------------

    public function test_movements_show_totals_by_type_with_consumption_net_of_reversals(): void
    {
        $ledger = app(StockLedger::class);
        $batch = $this->receive(Product::factory()->create(['name' => 'Gaze', 'cost' => null]), 'GZ1', '10', '2027-06-30', '2.00');
        $kept = Treatment::factory()->create();
        $voided = Treatment::factory()->create();
        $ledger->consume($batch, '3', $kept, $this->admin);
        $ledger->consume($batch, '2', $voided, $this->admin);
        $ledger->reverseTreatment($voided, 'Estorno', $this->admin);
        $ledger->lose($batch, '1', 'Quebra', $this->admin);

        $this->actingAs($this->admin)->get('/estoque/movimentacoes')->assertOk()
            ->assertSee('Resumo das movimentações filtradas', false)
            // Entrada: 10 × R$ 2,00. Consumo: 5 × 2 − 2 × 2 estornados. Perda: 1 × 2.
            ->assertSeeInOrder(['Entrada', 'R$ 20,00'])
            ->assertSeeInOrder(['Consumo em procedimento (líquido)', 'R$ 6,00', 'descontados os estornos'])
            ->assertSeeInOrder(['Perda', 'R$ 2,00']);

        // Busca pelo motivo.
        $this->get('/estoque/movimentacoes?q=Quebra')->assertSee('1 movimentação');
    }

    // --- Correções ---------------------------------------------------------

    public function test_entry_cost_is_corrected_with_the_adjust_permission_and_audited(): void
    {
        $batch = $this->receive(Product::factory()->create(['name' => 'Gaze']), 'GZ1', '10', '2027-06-30', '12000');
        $entry = StockMovement::where('type', StockMovementType::Entry->value)->sole();
        $url = "/estoque/movimentacoes/{$entry->id}/custo";

        $this->patch($url, ['unit_cost' => '12,00'])->assertRedirect('/login');

        app(RolePermissions::class)->sync(UserRole::Professional, [Permission::StockView, Permission::StockManage]);
        $this->actingAs(User::factory()->professional()->create())->get('/estoque/entradas')->assertDontSee('Corrigir custo');
        $this->patch($url, ['unit_cost' => '12,00'])->assertForbidden();

        $this->actingAs($this->admin)->get('/estoque/entradas')->assertSee('Corrigir custo');
        $this->patch($url, ['unit_cost' => 'abc'])->assertSessionHasErrors('unit_cost');
        $this->patch($url, ['unit_cost' => '12,00'])->assertSessionHas('success');

        $this->assertSame('12.0000', $entry->refresh()->unit_cost);
        $this->assertSame('10.000', $batch->refresh()->quantity);
        $log = AuditLog::where('action', AuditAction::StockEntryCostCorrected->value)->sole();
        $this->assertSame(['12000.0000', '12.0000'], [$log->metadata['from'], $log->metadata['to']]);

        // A baixa seguinte já usa o custo médio corrigido.
        $loss = app(StockLedger::class)->lose($batch, '1', 'Quebra', $this->admin);
        $this->assertSame('12.0000', $loss->unit_cost);

        // Só entrada tem custo corrigível.
        $this->patch("/estoque/movimentacoes/{$loss->id}/custo", ['unit_cost' => '1'])->assertForbidden();
    }

    public function test_batch_code_is_corrected_and_cannot_repeat_in_the_product(): void
    {
        $product = Product::factory()->create(['name' => 'Gaze']);
        $batch = $this->receive($product, 'GZ1-ERRADO', '10');
        $this->receive($product, 'GZ2', '5');

        $this->actingAs($this->admin);
        $this->patch("/estoque/lotes/{$batch->id}", ['batch_code' => 'GZ2', 'expires_on' => '2027-06-30'])->assertSessionHasErrors(['batch_code' => 'Já existe outro lote deste produto com este código.']);
        $this->patch("/estoque/lotes/{$batch->id}", ['batch_code' => 'GZ1', 'expires_on' => '2027-06-30'])->assertSessionHas('success', 'Dados do lote corrigidos.');

        $this->assertSame('GZ1', $batch->refresh()->batch_code);
        $log = AuditLog::where('action', AuditAction::ProductBatchUpdated->value)->sole();
        $this->assertEquals(['batch_code' => 'GZ1-ERRADO'], $log->metadata['from']);
        $this->assertEquals(['batch_code' => 'GZ1'], $log->metadata['to']);
    }

    public function test_an_entry_cost_far_from_the_last_one_asks_for_confirmation(): void
    {
        $product = Product::factory()->create(['name' => 'Gaze']);
        $this->receive($product, 'GZ1', '10', '2027-06-30', '12.00');

        $entry = ['_dialog' => 'stock-entry', 'product_id' => $product->id, 'batch_code' => 'GZ2', 'quantity' => '5', 'expires_on' => '2027-06-30'];

        // O diálogo volta com o aviso e a caixa de confirmação.
        $this->actingAs($this->admin)->post('/estoque/entradas', [...$entry, 'unit_cost' => '12000']);
        $this->get('/estoque/entradas')->assertSee('1.000,0 vezes maior')->assertSee('Confirmo o custo: o valor está certo');
        $this->assertSame(1, StockMovement::where('type', StockMovementType::Entry->value)->count());

        $this->post('/estoque/entradas', [...$entry, 'unit_cost' => '13,50'])->assertSessionHasNoErrors();
        $this->post('/estoque/entradas', [...$entry, 'batch_code' => 'GZ3', 'unit_cost' => '0,50', 'confirm_cost' => '1'])->assertSessionHasNoErrors();
        $this->assertSame(3, StockMovement::where('type', StockMovementType::Entry->value)->count());
    }

    // --- Lote vencido ------------------------------------------------------

    public function test_an_expired_batch_is_written_off_with_its_open_units(): void
    {
        $product = $this->toxin();
        $batch = $this->receive($product, 'TX1', '300', '2026-12-31', '10.00');
        $unit = app(StockLedger::class)->openUnit($batch, $this->admin);
        DB::table('product_batches')->where('id', $batch->id)->update(['expires_on' => '2026-10-01']);
        $url = "/estoque/lotes/{$batch->id}/baixa-vencido";

        $this->post($url, ['reason' => 'Vencido'])->assertRedirect('/login');
        app(RolePermissions::class)->sync(UserRole::Receptionist, [Permission::StockView]);
        $this->actingAs(User::factory()->create())->post($url, ['reason' => 'Vencido'])->assertForbidden();

        // Na página do lote vencido, só a baixa como perda: saída e ajuste nem aparecem.
        $this->actingAs($this->admin)->get("/estoque/lotes/{$batch->id}")->assertOk()
            ->assertSee('Baixar vencido como perda')->assertSee('Vencido em 01/10/2026')
            ->assertDontSee('data-dialog-open="stock-exit"', false)->assertDontSee('data-dialog-open="stock-adjustment"', false);
        $this->get('/estoque/lotes?situacao=vencidos')->assertSee('Baixar vencido');

        $this->post($url, ['reason' => ''])->assertSessionHasErrors('reason');
        $this->post($url, ['reason' => 'Vencido em 01/10/2026'])->assertSessionHasNoErrors();

        $this->assertSame('0.000', $batch->refresh()->quantity);
        $this->assertSame('0.000', $unit->refresh()->quantity);
        $this->assertSame(2, StockMovement::where('type', StockMovementType::Loss->value)->where('product_batch_id', $batch->id)->count());
        $this->assertSame(1, StockMovement::where('type', StockMovementType::Loss->value)->where('stock_unit_id', $unit->id)->count());

        // Lote dentro da validade não é baixado por aqui.
        $valid = $this->receive(Product::factory()->create(), 'OK1', '5');
        $this->post("/estoque/lotes/{$valid->id}/baixa-vencido", ['reason' => 'x'])->assertNotFound();
    }

    // --- Saldo disponível e cadastro ---------------------------------------

    public function test_available_quantity_leaves_out_what_awaits_discard(): void
    {
        $product = $this->toxin(['minimum_stock' => 250]);
        $batch = $this->receive($product, 'TX1', '300');
        app(StockLedger::class)->openUnit($batch, $this->admin);
        $this->travel(30)->hours();

        // 300 U no lote, 100 deles numa unidade aberta fora do prazo: disponível são 200.
        $this->actingAs($this->admin)->get('/estoque')->assertSee('200 U')->assertSee('+ 100 U a descartar')->assertSee('Estoque baixo');
        $this->get("/estoque/{$product->id}")->assertSee('+ 100 U a descartar');
        $this->assertSame(1, Product::query()->lowStock()->count());
    }

    public function test_deactivating_a_product_with_stock_asks_for_confirmation(): void
    {
        $product = Product::factory()->create(['name' => 'Gaze', 'category' => 'disposable', 'unit' => 'un']);
        $this->receive($product, 'GZ1', '10');
        $payload = ['name' => 'Gaze', 'category' => 'disposable', 'unit' => 'un', 'minimum_stock' => '0', 'is_active' => '0'];

        $this->actingAs($this->admin)->put("/estoque/{$product->id}", $payload)->assertSessionHasErrors('is_active');
        $this->assertTrue($product->refresh()->is_active);
        $this->assertStringContainsString('10 un em estoque', session('errors')->first('is_active'));

        $this->put("/estoque/{$product->id}", [...$payload, 'confirm_deactivate' => '1'])->assertSessionHasNoErrors();
        $this->assertFalse($product->refresh()->is_active);

        // Sem saldo, não pergunta.
        $empty = Product::factory()->create(['name' => 'Vazio', 'category' => 'disposable', 'unit' => 'un']);
        $this->put("/estoque/{$empty->id}", [...$payload, 'name' => 'Vazio'])->assertSessionHasNoErrors();
    }

    public function test_product_page_shows_the_average_cost_of_what_is_in_stock(): void
    {
        $product = Product::factory()->create(['name' => 'Agulha', 'cost' => '0.0350']);
        $this->receive($product, 'A1', '100', '2027-06-30', '0.04');
        $this->receive($product, 'A2', '100', '2027-06-30', '0.02');

        $this->actingAs($this->admin)->get("/estoque/{$product->id}")->assertOk()
            ->assertSee('Custo médio em estoque')->assertSee('R$ 0,03')
            ->assertSee('Custo de referência')->assertSee('R$ 0,035')
            ->assertDontSee('Preço unitário');
    }

    public function test_batches_filter_by_expiry_date_and_products_by_idle_stock(): void
    {
        $this->receive(Product::factory()->create(['name' => 'Vence Logo']), 'L1', '5', '2026-10-20');
        $this->receive(Product::factory()->create(['name' => 'Vence Depois']), 'L2', '5', '2027-03-01');
        $idle = $this->receive(Product::factory()->create(['name' => 'Parado']), 'P1', '5');

        $this->actingAs($this->admin);
        $this->get('/estoque/lotes?vence_ate=2026-10-31')->assertSee('Vence Logo')->assertDontSee('Vence Depois');

        $this->travel(100)->days();
        $this->receive(Product::factory()->create(['name' => 'Movimentado']), 'M1', '5');
        $this->get('/estoque?situacao=parados')->assertSee('Parado')->assertDontSee('Movimentado');
        $this->assertSame('5.000', $idle->refresh()->quantity);
    }

    public function test_products_tab_links_to_a_new_entry_and_empty_lists_offer_the_next_step(): void
    {
        $this->actingAs($this->admin)->get('/estoque')->assertSee(e(route('stock.entries.index', ['nova' => 1])), false)
            ->assertSee('Cadastrar o primeiro produto');
        $this->get('/estoque/entradas?nova=1')->assertSee('data-dialog-autoshow', false);
        $this->get('/estoque/entradas')->assertDontSee('data-dialog-autoshow', false);
        $this->get('/estoque?q=nada')->assertSee('Limpar filtros');
    }

    // --- Nota fiscal com fracionado ----------------------------------------

    public function test_new_fractioned_products_are_set_up_while_importing_an_invoice(): void
    {
        $this->actingAs($this->admin);
        $token = $this->importInvoice('TOXINA 100U', 'TX-100', 'FR', '2', '1900.00');

        $this->get('/estoque/nota-fiscal?importacao='.$token)->assertOk()->assertSee('Usado aos poucos (fracionado)?')->assertSee('data-invoice-new-product', false);

        $item = [
            'import' => '1', 'product_id' => '', 'name' => 'Toxina 100 U', 'code' => 'TX-100', 'category' => 'injectable', 'unit' => 'u',
            'total_cost' => '1900,00', 'enter' => '1', 'unit_content' => '100', 'hours_after_opening' => '24', 'multi_patient_use' => '1',
            'lots' => [['batch_code' => 'L1', 'quantity' => '2', 'manufactured_on' => '', 'expires_on' => '2027-06-30']],
        ];

        $this->put('/estoque/nota-fiscal', ['importacao' => $token, 'items' => [[...$item, 'lots' => [[...$item['lots'][0], 'quantity' => '1,5']]]]])
            ->assertSessionHasErrors('items.0.lots.0.quantity');

        $this->put('/estoque/nota-fiscal', ['importacao' => $token, 'items' => [$item]])->assertSessionHasNoErrors();

        $product = Product::where('name', 'Toxina 100 U')->sole();
        $this->assertSame([ProductUnit::ToxinUnit, '100.000', 24, true], [$product->unit, $product->unit_content, $product->hours_after_opening, $product->multi_patient_use]);
        // 2 frascos × 100 U; custo por U = 1.900 ÷ 200.
        $this->assertSame('200.000', ProductBatch::where('product_id', $product->id)->sole()->quantity);
        $this->assertSame('9.5000', StockMovement::sole()->unit_cost);
    }

    public function test_an_item_matched_to_a_fractioned_product_comes_converted(): void
    {
        $this->toxin(['name' => 'Toxina 100 U', 'code' => 'TX-100']);

        $this->actingAs($this->admin);
        $token = $this->importInvoice('TOXINA 100U', 'TX-100', 'FR', '3', '950.00');

        $this->get('/estoque/nota-fiscal?importacao='.$token)->assertOk()
            ->assertSee('A quantidade dos lotes já está na unidade do estoque')
            ->assertSee('name="items[0][lots][0][quantity]"', false)->assertSee('value="300"', false);
    }

    /**
     * Envia uma nota de um item com um lote e devolve o token da conferência.
     */
    private function importInvoice(string $name, string $code, string $unit, string $quantity, string $unitCost): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<nfeProc xmlns="http://www.portalfiscal.inf.br/nfe" versao="4.00"><NFe><infNFe Id="NFe35261012345678000199550010000045211000045299" versao="4.00">'
            .'<ide><nNF>99</nNF><serie>1</serie><dhEmi>2026-10-01T10:15:00-03:00</dhEmi></ide>'
            .'<emit><CNPJ>12345678000199</CNPJ><xNome>Distribuidora</xNome></emit>'
            ."<det nItem=\"1\"><prod><cProd>{$code}</cProd><cEAN>SEM GTIN</cEAN><xProd>{$name}</xProd><NCM>30021590</NCM><uCom>{$unit}</uCom>"
            ."<qCom>{$quantity}</qCom><vUnCom>{$unitCost}</vUnCom><vProd>".number_format((float) $quantity * (float) $unitCost, 2, '.', '').'</vProd>'
            ."<rastro><nLote>L1</nLote><qLote>{$quantity}</qLote><dFab>2026-05-10</dFab><dVal>2027-06-30</dVal></rastro></prod></det>"
            .'</infNFe></NFe></nfeProc>';

        $path = tempnam(sys_get_temp_dir(), 'nfe');
        file_put_contents($path, $xml);

        $response = $this->post('/estoque/nota-fiscal', [
            '_dialog' => 'invoice-import',
            'invoice' => new UploadedFile($path, 'nota.xml', 'text/xml', null, true),
        ]);

        parse_str((string) parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);

        return $query['importacao'];
    }

    public function test_an_expired_batch_is_written_off_with_only_the_discard_permission_and_audited_without_the_reason(): void
    {
        $product = Product::factory()->create(['name' => 'Gaze']);
        $batch = $this->expiredBatch($product, 'GZ1', '7');
        $url = "/estoque/lotes/{$batch->id}/baixa-vencido";

        // Quem só descarta, sem gerir o estoque, baixa o vencido.
        app(RolePermissions::class)->sync(UserRole::Receptionist, [Permission::StockView, Permission::StockDiscard]);
        $this->actingAs(User::factory()->create())->post($url, ['reason' => 'Vencido na prateleira'])->assertSessionHasNoErrors()->assertSessionHas('success');

        $this->assertSame('0.000', $batch->refresh()->quantity);
        $loss = StockMovement::where('type', StockMovementType::Loss->value)->where('product_batch_id', $batch->id)->sole();
        $this->assertSame('-7.000', $loss->quantity);

        $log = AuditLog::where('action', AuditAction::StockMoved->value)->where('auditable_id', $loss->id)->sole();
        $this->assertSame(['type' => 'loss', 'product_id' => $product->id, 'product_batch_id' => $batch->id], $log->metadata);
        $this->assertStringNotContainsString('Vencido na prateleira', (string) json_encode($log));

        // Lote já zerado: nada muda e a mensagem diz isso.
        $this->post($url, ['reason' => 'De novo'])->assertSessionHas('success', 'O lote já estava zerado. Nada foi alterado.');
        $this->assertSame(1, StockMovement::where('type', StockMovementType::Loss->value)->count());
    }

    public function test_entry_cost_is_corrected_by_a_non_admin_with_the_adjust_permission(): void
    {
        $this->receive(Product::factory()->create(['name' => 'Gaze']), 'GZ1', '10', '2027-06-30', '120.00');
        $entry = StockMovement::where('type', StockMovementType::Entry->value)->sole();

        app(RolePermissions::class)->sync(UserRole::Professional, [Permission::StockView, Permission::StockAdjust]);
        $this->actingAs(User::factory()->professional()->create())
            ->patch("/estoque/movimentacoes/{$entry->id}/custo", ['unit_cost' => '1.234,5678'])
            ->assertSessionHasNoErrors();

        $this->assertSame('1234.5678', $entry->refresh()->unit_cost);
        $this->assertSame(1, AuditLog::where('action', AuditAction::StockEntryCostCorrected->value)->count());
    }

    public function test_open_units_list_validates_the_product_filter(): void
    {
        $this->actingAs($this->admin)->get('/estoque/unidades?produto=abc')->assertSessionHasErrors('produto');
        $this->get('/estoque/unidades?produto=999999')->assertOk();
    }
}
