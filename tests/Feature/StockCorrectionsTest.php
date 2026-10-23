<?php

namespace Tests\Feature;

use App\Enums\AuditAction;
use App\Enums\ProductCategory;
use App\Enums\ProductUnit;
use App\Enums\StockMovementType;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\DashboardMetrics;
use App\Services\StockLedger;
use App\Services\StockReceiver;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Correções do estoque: quantidades com milhar, entrada em lote vencido,
 * produto fracionado em unidades inteiras, troca de unidade de medida,
 * precisão de custo e correção das datas de um lote.
 */
class StockCorrectionsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-08 13:00', 'America/Sao_Paulo'));
        $this->admin = User::factory()->admin()->create(['name' => 'Ana Admin']);
    }

    private function toxin(): Product
    {
        return Product::factory()->create(['name' => 'Toxina', 'unit' => ProductUnit::ToxinUnit, 'unit_content' => 100, 'multi_patient_use' => true]);
    }

    private function receive(Product $product, string $code, string $quantity, ?string $expiresOn = '2027-06-30', ?string $unitCost = null): ProductBatch
    {
        return app(StockReceiver::class)->receive($product->id, ['batch_code' => $code, 'quantity' => $quantity, 'expires_on' => $expiresOn, 'unit_cost' => $unitCost], $this->admin)->batch;
    }

    private function grant(UserRole $role, string ...$permissions): void
    {
        DB::table('role_permissions')->insert(array_map(fn (string $permission) => ['role' => $role->value, 'permission' => $permission], $permissions));
        $this->app->forgetScopedInstances();
    }

    public function test_quantities_of_a_thousand_or_more_keep_their_value_in_forms(): void
    {
        $product = Product::factory()->create(['name' => 'Luva', 'unit' => ProductUnit::Unit, 'minimum_stock' => 1000]);

        $this->actingAs($this->admin);

        // O formulário mostra o número sem separador de milhar.
        $this->get("/estoque/{$product->id}/editar")->assertOk()->assertSee('value="1000"', false);

        // E o padrão brasileiro com ponto de milhar também é lido como mil.
        $this->put("/estoque/{$product->id}", [
            'name' => 'Luva', 'category' => ProductCategory::Disposable->value, 'unit' => ProductUnit::Unit->value,
            'minimum_stock' => '1.500', 'is_active' => '1',
        ])->assertSessionHasNoErrors();
        $this->assertSame('1500.000', $product->refresh()->minimum_stock);

        $this->post('/estoque/entradas', ['product_id' => $product->id, 'batch_code' => 'LV1', 'quantity' => '2.000', 'expires_on' => '2027-06-30'])->assertSessionHasNoErrors();
        $this->assertSame('2000.000', ProductBatch::sole()->quantity);
    }

    public function test_entries_never_land_in_an_expired_batch(): void
    {
        $product = Product::factory()->create(['name' => 'Anestésico']);
        ProductBatch::factory()->create(['product_id' => $product->id, 'batch_code' => 'VENC', 'quantity' => 0, 'expires_on' => '2026-10-01']);

        $this->actingAs($this->admin);

        // Sem informar a validade, a entrada cairia no lote vencido.
        $this->post('/estoque/entradas', ['product_id' => $product->id, 'batch_code' => 'VENC', 'quantity' => '5'])
            ->assertSessionHasErrors(['batch_code' => 'O lote VENC venceu em 01/10/2026. Não é possível dar entrada em lote vencido.']);

        $this->assertSame(0, StockMovement::count());
        $this->assertSame('0.000', ProductBatch::sole()->quantity);
    }

    public function test_receiving_a_batch_created_meanwhile_reuses_it(): void
    {
        $product = Product::factory()->create();

        // Duas entradas do mesmo lote novo: a segunda soma ao lote, sem erro de duplicidade.
        $this->receive($product, 'L1', '2');
        $this->receive($product, 'L1', '3');

        $this->assertSame('5.000', ProductBatch::sole()->quantity);
    }

    public function test_fractioned_sealed_stock_moves_only_in_whole_units(): void
    {
        $toxin = $this->toxin();
        $batch = $this->receive($toxin, 'TX1', '300');
        app(StockLedger::class)->openUnit($batch, $this->admin);

        $this->actingAs($this->admin);
        $move = fn (string $type, string $quantity) => $this->post("/estoque/{$toxin->id}/movimentacoes", [
            'type' => $type, 'product_batch_id' => $batch->id, 'quantity' => $quantity, 'reason' => 'Motivo',
        ]);

        foreach (['exit', 'loss', 'adjustment'] as $type) {
            $move($type, '30')->assertSessionHasErrors('quantity');
        }
        $this->assertSame(0, StockMovement::where('type', '!=', StockMovementType::Entry->value)->count());

        // Ajuste: a contagem é só do lacrado (200), e a unidade aberta fica como está.
        $move('adjustment', '100')->assertSessionHasNoErrors();
        $this->assertSame('200.000', $batch->refresh()->quantity);
        $this->assertSame('-100.000', StockMovement::where('type', StockMovementType::Adjustment->value)->sole()->quantity);

        $move('loss', '100')->assertSessionHasNoErrors();
        $move('loss', '100')->assertSessionHasErrors(['quantity' => 'Saldo fechado insuficiente no lote TX1. Disponível: 0 U.']);
        $this->assertSame('100.000', $batch->refresh()->quantity);

        // O diálogo de ajuste explica o que se conta.
        $this->get("/estoque/lotes/{$batch->id}")->assertOk()->assertSee('Unidades fechadas contadas')->assertSee('TX1 · fechado 0 U');
    }

    public function test_inventory_of_fractioned_products_requires_whole_units(): void
    {
        $batch = $this->receive($this->toxin(), 'TX1', '300');

        $this->actingAs($this->admin)->put('/estoque/inventario', ['reason' => 'Inventário', 'counted' => [$batch->id => '250']])
            ->assertSessionHasErrors(["counted.{$batch->id}" => 'Conte só o fechado, em unidades inteiras de 100 U.']);

        $this->actingAs($this->admin)->put('/estoque/inventario', ['reason' => 'Inventário', 'counted' => [$batch->id => '200']])->assertSessionHasNoErrors();
        $this->assertSame('200.000', $batch->refresh()->quantity);
    }

    public function test_the_unit_of_measure_cannot_change_once_the_product_has_batches(): void
    {
        $product = Product::factory()->create(['name' => 'Toxina', 'unit' => ProductUnit::Vial]);
        $payload = ['name' => 'Toxina', 'category' => ProductCategory::Injectable->value, 'unit' => ProductUnit::ToxinUnit->value, 'minimum_stock' => '0', 'is_active' => '1'];

        $this->actingAs($this->admin);

        // Sem lotes, a unidade ainda pode ser corrigida.
        $this->put("/estoque/{$product->id}", $payload)->assertSessionHasNoErrors();
        $this->assertSame(ProductUnit::ToxinUnit, $product->refresh()->unit);

        $this->receive($product, 'TX1', '3');

        $this->put("/estoque/{$product->id}", [...$payload, 'unit' => ProductUnit::Vial->value])
            ->assertSessionHasErrors(['unit' => 'Este produto já tem lotes e movimentações nesta unidade de medida. Para controlar em outra unidade, cadastre um produto novo.']);
        $this->assertSame(ProductUnit::ToxinUnit, $product->refresh()->unit);
    }

    public function test_fractioning_requires_the_sealed_stock_to_form_whole_units(): void
    {
        $product = Product::factory()->create(['name' => 'Toxina', 'unit' => ProductUnit::ToxinUnit]);
        $this->receive($product, 'TX1', '250');
        $payload = ['name' => 'Toxina', 'category' => ProductCategory::Injectable->value, 'unit' => ProductUnit::ToxinUnit->value, 'minimum_stock' => '0', 'is_active' => '1'];

        $this->actingAs($this->admin);

        $this->put("/estoque/{$product->id}", [...$payload, 'unit_content' => '100'])
            ->assertSessionHasErrors(['unit_content' => 'O lote TX1 tem 250 U lacrados, que não formam unidades inteiras deste conteúdo. Ajuste o saldo do lote antes.']);
        $this->assertNull($product->refresh()->unit_content);

        $this->put("/estoque/{$product->id}", [...$payload, 'unit_content' => '50'])->assertSessionHasNoErrors();
        $this->assertSame('50.000', $product->refresh()->unit_content);

        // Salvar de novo com o mesmo conteúdo não refaz a conferência.
        $this->put("/estoque/{$product->id}", [...$payload, 'unit_content' => '50', 'name' => 'Toxina A'])->assertSessionHasNoErrors();
    }

    public function test_unit_costs_keep_fractions_of_a_cent(): void
    {
        $product = Product::factory()->create(['name' => 'Agulha 30G', 'unit' => ProductUnit::Unit]);

        $this->actingAs($this->admin)->post('/estoque/entradas', [
            'product_id' => $product->id, 'batch_code' => 'AG1', 'quantity' => '1000', 'expires_on' => '2027-06-30', 'unit_cost' => '0,085',
        ])->assertSessionHasNoErrors();

        $this->assertSame('0.0850', StockMovement::sole()->unit_cost);
        $this->get('/estoque/entradas')->assertOk()->assertSee('R$ 0,085');

        app(StockLedger::class)->withdraw(ProductBatch::sole(), '100', 'Uso interno', $this->admin);
        $exit = StockMovement::where('type', StockMovementType::Exit->value)->sole();
        $this->assertSame(['0.0850', '8.50'], [$exit->unit_cost, $exit->value()]);
    }

    public function test_batch_dates_are_corrected_with_the_adjust_permission_and_audited(): void
    {
        $product = Product::factory()->create(['name' => 'Anestésico']);
        $batch = $this->receive($product, 'AN1', '5', '2027-06-30');
        $url = "/estoque/lotes/{$batch->id}";

        $this->patch($url, ['expires_on' => '2027-07-30'])->assertRedirect('/login');

        // Movimentar estoque não basta: corrigir datas exige a permissão de ajuste.
        $this->grant(UserRole::Professional, 'stock.manage');
        $professional = User::factory()->professional()->create();
        $this->actingAs($professional)->get($url)->assertOk()->assertDontSee('Corrigir lote');
        $this->actingAs($professional)->patch($url, ['expires_on' => '2027-07-30'])->assertForbidden();

        $this->actingAs($this->admin)->get($url)->assertOk()->assertSee('Corrigir lote');
        $this->actingAs($this->admin)->patch($url, ['manufactured_on' => '2026-11-01'])->assertSessionHasErrors('manufactured_on');
        $this->actingAs($this->admin)->patch($url, ['manufactured_on' => '2026-01-10', 'expires_on' => '2026-01-01'])->assertSessionHasErrors('expires_on');

        $this->actingAs($this->admin)->patch($url, ['manufactured_on' => '2026-01-10', 'expires_on' => '2027-07-30'])
            ->assertRedirect($url)->assertSessionHas('success', 'Dados do lote corrigidos.');

        $batch->refresh();
        $this->assertSame(['2026-01-10', '2027-07-30'], [$batch->manufactured_on->format('Y-m-d'), $batch->expires_on->format('Y-m-d')]);

        $log = AuditLog::where('action', AuditAction::ProductBatchUpdated->value)->sole();
        $this->assertSame(['product_batch', $batch->id], [$log->auditable_type, $log->auditable_id]);
        $this->assertSame(['manufactured_on', 'expires_on'], $log->metadata['fields']);
        // O JSON do banco não guarda a ordem das chaves.
        $this->assertEquals(['manufactured_on' => null, 'expires_on' => '2027-06-30'], $log->metadata['from']);
        $this->assertEquals(['manufactured_on' => '2026-01-10', 'expires_on' => '2027-07-30'], $log->metadata['to']);

        // Nada mudou: nada a registrar.
        $this->actingAs($this->admin)->patch($url, ['manufactured_on' => '2026-01-10', 'expires_on' => '2027-07-30'])
            ->assertSessionHas('success', 'Os dados já estavam assim. Nada foi alterado.');
        $this->assertSame(1, AuditLog::where('action', AuditAction::ProductBatchUpdated->value)->count());
        // O saldo não muda por aqui.
        $this->assertSame('5.000', $batch->refresh()->quantity);
    }

    public function test_dashboard_low_stock_count_follows_the_stock_rule(): void
    {
        $low = Product::factory()->create(['minimum_stock' => 10]);
        $this->receive($low, 'L1', '5');
        $fine = Product::factory()->create(['minimum_stock' => 10]);
        $this->receive($fine, 'F1', '50');
        // Saldo vencido não conta como disponível.
        $expired = Product::factory()->create(['minimum_stock' => 10]);
        ProductBatch::factory()->create(['product_id' => $expired->id, 'quantity' => 50, 'expires_on' => '2026-10-01']);

        $totals = app(DashboardMetrics::class)->totals($this->admin);

        $this->assertSame(2, $totals['low_stock']);
        $this->assertSame(Product::query()->lowStock()->count(), $totals['low_stock']);
    }

    public function test_receiver_reports_a_date_conflict_on_the_field(): void
    {
        $product = Product::factory()->create();
        $this->receive($product, 'L1', '2', '2027-06-30');

        $this->expectException(ValidationException::class);
        $this->receive($product, 'L1', '1', '2027-07-30');
    }
}
