<?php

namespace Tests\Feature;

use App\Enums\ProductCategory;
use App\Enums\ProductUnit;
use App\Enums\StockMovementType;
use App\Exceptions\InsufficientStockException;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\StockLedger;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class StockTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-08 13:00', 'America/Sao_Paulo'));
        $this->admin = User::factory()->admin()->create(['name' => 'Ana Admin']);
    }

    /**
     * @return array<string, mixed>
     */
    private function productPayload(array $overrides = []): array
    {
        return [
            'name' => 'Toxina botulínica 100 U',
            'category' => ProductCategory::Injectable->value,
            'brand' => 'Botulift',
            'manufacturer' => 'Laboratório X',
            'supplier' => 'Distribuidora Y',
            'unit' => ProductUnit::ToxinUnit->value,
            'minimum_stock' => '200',
            'cost' => '1.234,56',
            'price' => '',
            'is_active' => '1',
            ...$overrides,
        ];
    }

    private function move(Product $product, array $data): TestResponse
    {
        return $this->post("/estoque/{$product->id}/movimentacoes", $data);
    }

    private function entry(Product $product, string $code, string $quantity, ?string $expiresOn = '2027-06-30'): TestResponse
    {
        return $this->move($product, ['type' => 'entry', 'batch_code' => $code, 'expires_on' => $expiresOn, 'quantity' => $quantity]);
    }

    public function test_access_follows_the_role_permissions(): void
    {
        $product = Product::factory()->create(['name' => 'Seringa 1 mL']);
        $batch = ProductBatch::factory()->create(['product_id' => $product->id]);

        $this->get('/estoque')->assertRedirect('/login');

        // Recepção: sem acesso ao estoque por padrão.
        $this->actingAs(User::factory()->create());
        $this->get('/estoque')->assertForbidden();
        $this->get("/estoque/{$product->id}")->assertForbidden();
        $this->get('/')->assertDontSee(route('products.index'), false);

        // Profissional: consulta, mas não cadastra nem movimenta.
        $this->actingAs(User::factory()->professional()->create());
        $this->get('/estoque')->assertOk()->assertSee('Seringa 1 mL')->assertDontSee('Novo produto');
        $this->get("/estoque/{$product->id}")->assertOk()->assertDontSee('Entrada de estoque')->assertDontSee('data-dialog-open', false);
        $this->get('/estoque/novo')->assertForbidden();
        $this->post('/estoque', $this->productPayload())->assertForbidden();
        $this->put("/estoque/{$product->id}", $this->productPayload())->assertForbidden();
        $this->move($product, ['type' => 'exit', 'product_batch_id' => $batch->id, 'quantity' => '1'])->assertForbidden();

        $this->assertSame('10.000', $batch->refresh()->quantity);
        $this->assertSame(0, StockMovement::count());
    }

    public function test_admin_can_register_and_update_a_product(): void
    {
        $this->actingAs($this->admin);
        $this->get('/estoque/novo')->assertOk();

        $this->post('/estoque', $this->productPayload())->assertSessionHasNoErrors();

        $product = Product::sole();

        $this->assertSame(ProductUnit::ToxinUnit, $product->unit);
        $this->assertSame('200.000', $product->minimum_stock);
        $this->assertSame('1234.56', $product->cost);
        $this->assertNull($product->price);

        $this->get("/estoque/{$product->id}")->assertOk()->assertSee('Toxina botulínica 100 U')->assertSee('R$ 1.234,56')->assertSee('Nenhum lote ainda');

        $this->put("/estoque/{$product->id}", $this->productPayload(['minimum_stock' => '2,5', 'price' => '1800', 'is_active' => '0']))
            ->assertRedirect(route('products.show', $product))->assertSessionHasNoErrors();

        $product->refresh();

        $this->assertSame('2.500', $product->minimum_stock);
        $this->assertSame('1800.00', $product->price);
        $this->assertFalse($product->is_active);
    }

    public function test_product_data_is_validated(): void
    {
        Product::factory()->create(['name' => 'Já Existe']);
        $this->actingAs($this->admin);

        $invalid = [
            'name' => ['', 'Já Existe', str_repeat('a', 151)],
            'category' => ['', 'x'],
            'unit' => ['', 'litro'],
            'minimum_stock' => ['', '-1', 'abc', '1,2345'],
            'cost' => ['-1', 'abc', '1,999'],
            'price' => ['-1', '1000000'],
            'brand' => [str_repeat('a', 101)],
        ];

        foreach ($invalid as $field => $values) {
            foreach ($values as $value) {
                $this->post('/estoque', $this->productPayload([$field => $value]))->assertSessionHasErrors($field);
            }
        }

        $this->assertSame(1, Product::count());
    }

    public function test_entry_creates_the_batch_and_later_entries_add_to_it(): void
    {
        $product = Product::factory()->create(['unit' => ProductUnit::ToxinUnit]);

        $this->actingAs($this->admin);

        $this->move($product, ['type' => 'entry', 'batch_code' => ' C4821A ', 'expires_on' => '2027-06-30', 'quantity' => '300', 'reason' => 'NF 1234'])
            ->assertRedirect(route('products.show', $product))->assertSessionHasNoErrors();
        $this->entry($product, 'C4821A', '100,5')->assertSessionHasNoErrors();
        // Segunda entrada sem repetir a validade também é aceita.
        $this->entry($product, 'C4821A', '0,5', null)->assertSessionHasNoErrors();

        $batch = ProductBatch::sole();

        $this->assertSame('C4821A', $batch->batch_code);
        $this->assertSame('2027-06-30', $batch->expires_on->format('Y-m-d'));
        $this->assertSame('401.000', $batch->quantity);

        $movements = StockMovement::orderBy('id')->get();

        $this->assertCount(3, $movements);
        $this->assertSame(StockMovementType::Entry, $movements[0]->type);
        $this->assertSame('300.000', $movements[0]->quantity);
        $this->assertSame('NF 1234', $movements[0]->reason);
        $this->assertSame($this->admin->id, $movements[0]->user_id);

        $this->get("/estoque/{$product->id}")
            ->assertSee('C4821A')->assertSee('30/06/2027')->assertSee('401 U')->assertSee('+300 U')->assertSee('NF 1234')->assertSee('Ana Admin');
    }

    public function test_entry_is_validated(): void
    {
        $product = Product::factory()->create();
        ProductBatch::factory()->create(['product_id' => $product->id, 'batch_code' => 'L1', 'expires_on' => '2027-01-31']);

        $this->actingAs($this->admin);

        $this->entry($product, '', '10')->assertSessionHasErrors('batch_code');
        $this->entry($product, 'L2', '0')->assertSessionHasErrors('quantity');
        $this->entry($product, 'L2', '-5')->assertSessionHasErrors('quantity');
        $this->entry($product, 'L2', '1,2345')->assertSessionHasErrors('quantity');
        $this->entry($product, 'L2', 'dez')->assertSessionHasErrors('quantity');
        // Lote já vencido não entra.
        $this->entry($product, 'L2', '10', '2026-10-07')->assertSessionHasErrors('expires_on');
        // A validade de um lote existente não é trocada por uma entrada.
        $this->entry($product, 'L1', '10', '2028-12-31')->assertSessionHasErrors('expires_on');
        // Na entrada o lote vem pelo código, não por id.
        $this->move($product, ['type' => 'entry', 'batch_code' => 'L2', 'quantity' => '1', 'product_batch_id' => 1])->assertSessionHasErrors('product_batch_id');
        $this->move($product, ['type' => 'compra', 'quantity' => '1'])->assertSessionHasErrors('type');

        $this->assertSame(1, ProductBatch::count());
        $this->assertSame(0, StockMovement::count());
    }

    public function test_exit_reduces_the_batch_and_cannot_exceed_its_balance(): void
    {
        $product = Product::factory()->create();
        $batch = ProductBatch::factory()->create(['product_id' => $product->id, 'batch_code' => 'L1', 'quantity' => 10]);
        $foreign = ProductBatch::factory()->create(['quantity' => 50]);

        $this->actingAs($this->admin);

        $this->move($product, ['type' => 'exit', 'product_batch_id' => $batch->id, 'quantity' => '2,5', 'reason' => 'Quebra'])->assertSessionHasNoErrors();
        $this->assertSame('7.500', $batch->refresh()->quantity);
        $this->assertSame('-2.500', StockMovement::sole()->quantity);

        $this->move($product, ['type' => 'exit', 'product_batch_id' => $batch->id, 'quantity' => '7,501'])
            ->assertSessionHasErrors(['quantity' => 'Saldo insuficiente no lote L1. Disponível: 7,5.']);
        // Lote de outro produto não pode ser movimentado por esta página.
        $this->move($product, ['type' => 'exit', 'product_batch_id' => $foreign->id, 'quantity' => '1'])->assertSessionHasErrors('product_batch_id');
        $this->move($product, ['type' => 'exit', 'quantity' => '1'])->assertSessionHasErrors('product_batch_id');
        $this->move($product, ['type' => 'exit', 'product_batch_id' => $batch->id, 'quantity' => '1', 'batch_code' => 'X'])->assertSessionHasErrors('batch_code');

        // Zerar o lote é permitido.
        $this->move($product, ['type' => 'exit', 'product_batch_id' => $batch->id, 'quantity' => '7,5'])->assertSessionHasNoErrors();

        $this->assertSame('0.000', $batch->refresh()->quantity);
        $this->assertSame('50.000', $foreign->refresh()->quantity);
        $this->assertSame(2, StockMovement::count());
    }

    public function test_adjustment_sets_the_counted_quantity_and_records_the_difference(): void
    {
        $product = Product::factory()->create();
        $batch = ProductBatch::factory()->create(['product_id' => $product->id, 'quantity' => 10]);
        $url = ['type' => 'adjustment', 'product_batch_id' => $batch->id];

        $this->actingAs($this->admin);

        $this->move($product, [...$url, 'quantity' => '8'])->assertSessionHasErrors('reason');

        $this->move($product, [...$url, 'quantity' => '8', 'reason' => 'Inventário'])->assertSessionHasNoErrors();
        $this->assertSame('8.000', $batch->refresh()->quantity);

        $this->move($product, [...$url, 'quantity' => '12,25', 'reason' => 'Recontagem'])->assertSessionHasNoErrors();
        $this->assertSame('12.250', $batch->refresh()->quantity);

        // Contagem igual ao saldo: nada é registrado.
        $this->move($product, [...$url, 'quantity' => '12,25', 'reason' => 'Conferência'])
            ->assertSessionHas('success', 'A contagem confere com o saldo. Nada foi alterado.');

        $this->move($product, [...$url, 'quantity' => '0', 'reason' => 'Descarte total'])->assertSessionHasNoErrors();
        $this->move($product, [...$url, 'quantity' => '-1', 'reason' => 'Inválido'])->assertSessionHasErrors('quantity');

        $this->assertSame('0.000', $batch->refresh()->quantity);
        $this->assertSame(['-2.000', '4.250', '-12.250'], StockMovement::orderBy('id')->pluck('quantity')->all());
        $this->assertSame([StockMovementType::Adjustment], StockMovement::all()->pluck('type')->unique()->values()->all());
    }

    public function test_balance_always_equals_the_sum_of_the_movements(): void
    {
        $product = Product::factory()->create();

        $this->actingAs($this->admin);

        // 0,1 + 0,2 daria 0,30000000000000004 em ponto flutuante.
        $this->entry($product, 'L1', '0,1');
        $this->entry($product, 'L1', '0,2');
        $this->entry($product, 'L1', '0,7');

        $batch = ProductBatch::sole();
        $this->move($product, ['type' => 'exit', 'product_batch_id' => $batch->id, 'quantity' => '0,3']);
        $this->move($product, ['type' => 'adjustment', 'product_batch_id' => $batch->id, 'quantity' => '0,65', 'reason' => 'Contagem']);

        $this->assertSame('0.650', $batch->refresh()->quantity);
        $this->assertSame('0.650', number_format((float) DB::table('stock_movements')->sum('quantity'), 3, '.', ''));
    }

    public function test_ledger_rejects_withdrawals_beyond_the_balance_and_changes_nothing(): void
    {
        $batch = ProductBatch::factory()->create(['quantity' => 5]);

        try {
            app(StockLedger::class)->withdraw($batch, '5.001', null, $this->admin);
            $this->fail('A retirada deveria ter sido recusada.');
        } catch (InsufficientStockException $exception) {
            $this->assertSame('5.000', $exception->available);
        }

        $this->assertSame('5.000', $batch->refresh()->quantity);
        $this->assertSame(0, StockMovement::count());
    }

    public function test_movements_are_immutable_and_balance_cannot_be_mass_assigned(): void
    {
        $batch = ProductBatch::factory()->create(['quantity' => 5]);
        $movement = app(StockLedger::class)->enter($batch, '1', null, $this->admin);

        $this->assertSame('6.000', $batch->quantity);

        try {
            $batch->update(['quantity' => 999]);
            $this->fail('O saldo não deveria aceitar atribuição em massa.');
        } catch (MassAssignmentException) {
            $this->assertSame('6.000', $batch->refresh()->quantity);
        }

        $this->expectException(\LogicException::class);
        $movement->delete();
    }

    public function test_expired_batches_do_not_count_as_available_stock(): void
    {
        $product = Product::factory()->create(['minimum_stock' => 5, 'unit' => ProductUnit::Milliliter]);
        ProductBatch::factory()->create(['product_id' => $product->id, 'batch_code' => 'VENCIDO', 'expires_on' => '2026-10-07', 'quantity' => 100]);
        ProductBatch::factory()->create(['product_id' => $product->id, 'batch_code' => 'BOM', 'expires_on' => '2026-10-08', 'quantity' => 4]);

        $this->actingAs($this->admin);

        // Só os 4 mL do lote que vence hoje contam: abaixo do mínimo de 5.
        $this->get("/estoque/{$product->id}")->assertOk()->assertSee('4 mL')->assertSee('Estoque baixo')->assertSee('Vencido')->assertSee('Vence hoje');
        $this->get('/estoque?situacao=baixo')->assertSee($product->name);
        $this->get('/estoque?situacao=vencendo')->assertSee($product->name);
    }

    public function test_listing_shows_alerts_and_can_be_filtered(): void
    {
        $healthy = Product::factory()->create(['name' => 'Agulha 30G', 'category' => ProductCategory::Disposable, 'minimum_stock' => 5]);
        ProductBatch::factory()->create(['product_id' => $healthy->id, 'expires_on' => '2028-01-01', 'quantity' => 50]);

        $low = Product::factory()->create(['name' => 'Ácido hialurônico', 'minimum_stock' => 3]);
        ProductBatch::factory()->create(['product_id' => $low->id, 'expires_on' => '2028-01-01', 'quantity' => 3]);

        $expiring = Product::factory()->create(['name' => 'Anestésico tópico', 'category' => ProductCategory::Anesthetic]);
        ProductBatch::factory()->create(['product_id' => $expiring->id, 'expires_on' => '2026-11-15', 'quantity' => 2]);

        $noMinimum = Product::factory()->create(['name' => 'Sem mínimo', 'minimum_stock' => 0]);
        $inactive = Product::factory()->create(['name' => 'Descontinuado', 'is_active' => false]);

        $this->actingAs($this->admin);

        $this->get('/estoque')->assertSee('Agulha 30G')->assertSee('Ácido hialurônico')->assertSee('Sem mínimo')->assertDontSee('Descontinuado')
            ->assertSee('Estoque baixo')->assertSee('Vencendo');
        $this->get('/estoque?situacao=baixo')->assertSee('Ácido hialurônico')->assertDontSee('Agulha 30G')->assertDontSee('Sem mínimo')->assertDontSee('Anestésico tópico');
        $this->get('/estoque?situacao=vencendo')->assertSee('Anestésico tópico')->assertDontSee('Agulha 30G');
        $this->get('/estoque?situacao=inativos')->assertSee('Descontinuado')->assertDontSee('Agulha 30G');
        $this->get('/estoque?situacao=todos')->assertSee('Descontinuado')->assertSee('Agulha 30G');
        $this->get('/estoque?categoria=disposable')->assertSee('Agulha 30G')->assertDontSee('Ácido hialurônico');
        $this->get('/estoque?q=hialur')->assertSee('Ácido hialurônico')->assertDontSee('Agulha 30G');
        $this->get('/estoque?situacao=x')->assertSessionHasErrors('situacao');
    }

    public function test_listing_does_not_run_one_query_per_product(): void
    {
        Product::factory()->count(12)->create()->each(fn (Product $product) => ProductBatch::factory()->count(2)->create(['product_id' => $product->id]));

        $this->actingAs($this->admin);
        DB::enableQueryLog();

        $this->get('/estoque')->assertOk();

        // Sessão/usuário à parte, a listagem usa contagem + uma consulta com os saldos e alertas.
        $queries = collect(DB::getQueryLog())->filter(fn (array $query) => str_contains($query['query'], 'product'));

        $this->assertLessThanOrEqual(2, $queries->count());
    }

    public function test_a_dialog_reopens_with_its_own_errors_only(): void
    {
        $product = Product::factory()->create();
        $batch = ProductBatch::factory()->create(['product_id' => $product->id, 'quantity' => 1]);

        $this->actingAs($this->admin);

        $html = $this->followingRedirects()
            ->from("/estoque/{$product->id}")
            ->post("/estoque/{$product->id}/movimentacoes", ['_dialog' => 'stock-exit', 'type' => 'exit', 'product_batch_id' => $batch->id, 'quantity' => '5'])
            ->getContent();

        $this->assertMatchesRegularExpression('/<dialog id="stock-exit"[^>]*data-dialog-autoshow/', $html);
        $this->assertDoesNotMatchRegularExpression('/<dialog id="stock-entry"[^>]*data-dialog-autoshow/', $html);
        // O erro aparece só no diálogo de saída, não nos campos homônimos dos outros.
        $this->assertSame(1, substr_count($html, 'Saldo insuficiente no lote'));
        $this->assertStringContainsString('id="exit-quantity-error"', $html);
        $this->assertStringNotContainsString('id="entry-quantity-error"', $html);
    }

    public function test_output_is_escaped(): void
    {
        $product = Product::factory()->create(['name' => '<script>alert(1)</script>', 'supplier' => '<img src=x onerror=alert(2)>']);
        ProductBatch::factory()->create(['product_id' => $product->id, 'batch_code' => '"><script>alert(3)</script>']);

        $this->actingAs($this->admin);

        $this->get('/estoque')->assertDontSee('<script>alert(1)</script>', false);
        $this->get("/estoque/{$product->id}")
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertDontSee('<img src=x onerror=alert(2)>', false)
            ->assertDontSee('<script>alert(3)</script>', false);
    }
}
