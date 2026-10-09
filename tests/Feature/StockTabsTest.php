<?php

namespace Tests\Feature;

use App\Enums\AuditAction;
use App\Enums\Permission;
use App\Enums\ProductUnit;
use App\Enums\StockMovementType;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Patient;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\StockMovement;
use App\Models\Treatment;
use App\Models\User;
use App\Services\RolePermissions;
use App\Services\StockLedger;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * As quatro abas do estoque (Produtos, Entradas, Movimentações, Lotes e
 * validade), o inventário e as regras que valem para todas.
 */
class StockTabsTest extends TestCase
{
    use RefreshDatabase;

    private const TABS = ['produtos' => '/estoque', 'entradas' => '/estoque/entradas', 'movimentacoes' => '/estoque/movimentacoes', 'lotes' => '/estoque/lotes'];

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-08 13:00', 'America/Sao_Paulo'));
        $this->admin = User::factory()->admin()->create(['name' => 'Ana Admin']);
    }

    private function batch(string $productName, string $code, float|int $quantity, ?string $expiresOn = '2027-06-30', array $productAttributes = []): ProductBatch
    {
        $product = Product::query()->where('name', $productName)->first()
            ?? Product::factory()->create(['name' => $productName, ...$productAttributes]);

        return ProductBatch::factory()->create(['product_id' => $product->id, 'batch_code' => $code, 'quantity' => $quantity, 'expires_on' => $expiresOn]);
    }

    private function entry(Product $product, array $data): TestResponse
    {
        return $this->post('/estoque/entradas', ['product_id' => $product->id, ...$data]);
    }

    private function move(ProductBatch $batch, string $type, string $quantity, ?string $reason = 'Motivo do teste'): TestResponse
    {
        return $this->post("/estoque/{$batch->product_id}/movimentacoes", ['type' => $type, 'product_batch_id' => $batch->id, 'quantity' => $quantity, 'reason' => $reason]);
    }

    /**
     * Um lote com uma movimentação de cada tipo, a última delas o consumo em um atendimento.
     *
     * @return array{ProductBatch, Treatment}
     */
    private function batchWithEveryKindOfMovement(): array
    {
        $batch = $this->batch('Toxina 100 U', 'TX1', 0, '2027-06-30', ['unit' => ProductUnit::ToxinUnit]);
        $ledger = app(StockLedger::class);

        $ledger->enter($batch, '100', 'Compra do mês', $this->admin, ['invoice_number' => '77', 'supplier' => 'Distribuidora Alfa']);
        $ledger->withdraw($batch, '5', 'Devolução ao fornecedor', $this->admin);
        $ledger->lose($batch, '2', 'Frasco quebrado', $this->admin);
        $ledger->adjustTo($batch, '90', 'Conferência mensal', $this->admin);

        $treatment = Treatment::factory()->finalized()->create([
            'patient_id' => Patient::factory()->create(['name' => 'Paciente Sigiloso'])->id,
            'professional_id' => User::factory()->professional()->create(['name' => 'Dra. Helena'])->id,
        ]);
        $ledger->consume($batch, '30', $treatment, $this->admin);

        return [$batch, $treatment];
    }

    public function test_tabs_follow_the_stock_permissions(): void
    {
        $batch = $this->batch('Seringa 1 mL', 'S1', 10);
        $pages = [...self::TABS, "/estoque/lotes/{$batch->id}"];

        foreach ($pages as $url) {
            $this->get($url)->assertRedirect('/login');
        }

        // Recepção: sem acesso ao estoque por padrão.
        $this->actingAs(User::factory()->create());

        foreach ([...$pages, '/estoque/inventario'] as $url) {
            $this->get($url)->assertForbidden();
        }

        // Profissional: consulta todas as abas, sem lançar nada.
        $this->actingAs(User::factory()->professional()->create());

        foreach ($pages as $url) {
            $this->get($url)->assertOk()->assertDontSee('data-dialog-open', false)->assertDontSee('Inventário');
        }

        $this->get('/estoque/inventario')->assertForbidden();
        $this->put('/estoque/inventario', ['reason' => 'Inventário', 'counted' => [$batch->id => '1']])->assertForbidden();
        $this->entry($batch->product, ['batch_code' => 'S2', 'quantity' => '5'])->assertForbidden();
        $this->move($batch, 'loss', '1')->assertForbidden();

        $this->assertSame('10.000', $batch->refresh()->quantity);
        $this->assertSame(0, StockMovement::count());
    }

    public function test_every_tab_shows_the_navigation_and_the_stock_alerts(): void
    {
        $this->batch('Produto Baixo', 'B1', 3, '2028-01-01', ['minimum_stock' => 5]);
        $this->batch('Produto Vencido', 'V1', 4, '2026-10-01');
        $this->batch('Produto Vencendo', 'P1', 2, '2026-11-15');

        $this->actingAs($this->admin);

        foreach (self::TABS as $key => $url) {
            $response = $this->get($url)->assertOk()
                ->assertSee('1 produto com estoque baixo')
                ->assertSee('1 lote vencido com saldo')
                ->assertSee('1 lote perto do vencimento')
                ->assertSee('href="'.route('products.index', ['situacao' => 'baixo']).'"', false)
                ->assertSee('href="'.route('stock.batches.index', ['situacao' => 'vencidos']).'"', false);

            foreach (self::TABS as $other => $otherUrl) {
                $pattern = '/<a href="[^"]*'.preg_quote($otherUrl, '/').'" class="[^"]*"\s+aria-current="page"\s*>/';

                $key === $other
                    ? $this->assertMatchesRegularExpression($pattern, $response->getContent(), "Aba {$key}")
                    : $this->assertDoesNotMatchRegularExpression($pattern, $response->getContent(), "Aba {$key}");
            }

            // Em qualquer aba o item Estoque do menu continua destacado.
            $this->assertMatchesRegularExpression('/<a href="[^"]*\/estoque"[^>]*title="Estoque"[^>]*aria-current="page"/', $response->getContent());
        }
    }

    public function test_a_healthy_stock_shows_no_alerts(): void
    {
        $this->batch('Produto Saudável', 'OK1', 50, '2028-01-01', ['minimum_stock' => 5]);

        $this->actingAs($this->admin)->get('/estoque')->assertOk()->assertDontSee('Alertas do estoque')->assertDontSee('com estoque baixo');
    }

    public function test_entries_tab_lists_the_purchases_and_filters_them(): void
    {
        $toxin = Product::factory()->create(['name' => 'Toxina 100 U', 'unit' => ProductUnit::ToxinUnit]);
        $needle = Product::factory()->create(['name' => 'Agulha 30G']);

        $this->actingAs($this->admin);

        $this->travelTo(CarbonImmutable::parse('2026-10-01 10:00', 'America/Sao_Paulo'));
        $this->entry($toxin, [
            'batch_code' => 'TX1', 'quantity' => '100', 'manufactured_on' => '2026-06-01', 'expires_on' => '2027-06-30',
            'unit_cost' => '12,5', 'supplier' => 'Distribuidora Alfa', 'invoice_number' => 'NF-881',
        ])->assertRedirect(route('stock.entries.index'))->assertSessionHas('success', 'Entrada registrada.');

        $this->travelTo(CarbonImmutable::parse('2026-10-08 13:00', 'America/Sao_Paulo'));
        $this->entry($needle, ['batch_code' => 'AG7', 'quantity' => '50', 'supplier' => 'Beta Descartáveis', 'invoice_number' => 'NF-902'])->assertSessionHasNoErrors();
        // Saída não é entrada: fica só na aba de movimentações.
        $this->move(ProductBatch::where('batch_code', 'AG7')->sole(), 'exit', '3', 'Uso interno na recepção');

        $this->get('/estoque/entradas')
            ->assertOk()
            ->assertSee('2 entradas')
            // Da mais recente para a mais antiga.
            ->assertSeeInOrder(['AG7', 'TX1'])
            ->assertSee('NF-881')->assertSee('Distribuidora Alfa')->assertSee('R$ 12,50')->assertSee('01/06/2026')->assertSee('30/06/2027')->assertSee('+100 U')
            ->assertSee('Ana Admin')
            ->assertDontSee('Uso interno na recepção');

        $this->get('/estoque/entradas?q=NF-902')->assertSee('AG7')->assertDontSee('TX1');
        $this->get('/estoque/entradas?q=alfa')->assertSee('TX1')->assertDontSee('AG7');
        $this->get('/estoque/entradas?q=toxina')->assertSee('TX1')->assertDontSee('AG7');
        $this->get('/estoque/entradas?q=AG7')->assertSee('AG7')->assertDontSee('TX1');
        $this->get('/estoque/entradas?de=2026-10-08')->assertSee('AG7')->assertDontSee('TX1');
        $this->get('/estoque/entradas?ate=2026-10-01')->assertSee('TX1')->assertDontSee('AG7');
        $this->get('/estoque/entradas?q=nada-disso')->assertSee('Nenhuma entrada encontrada.');
        $this->get('/estoque/entradas?de=01/10/2026')->assertSessionHasErrors('de');
    }

    public function test_new_entry_dialog_offers_only_active_products_and_reopens_with_its_errors(): void
    {
        $active = Product::factory()->create(['name' => 'Produto Ativo', 'code' => 'PA-01']);
        Product::factory()->create(['name' => 'Produto Aposentado', 'is_active' => false]);

        $this->actingAs($this->admin);

        $this->get('/estoque/entradas')
            ->assertSee('<dialog id="stock-entry"', false)
            ->assertSee('data-dialog-open="stock-entry"', false)
            ->assertSee('Produto Ativo · PA-01 (un)')
            ->assertDontSee('Produto Aposentado')
            ->assertDontSee('data-dialog-autoshow', false);

        $this->followingRedirects()->from('/estoque/entradas')
            ->post('/estoque/entradas', ['_dialog' => 'stock-entry', 'product_id' => $active->id, 'batch_code' => 'L1', 'quantity' => '0', 'supplier' => 'Fornecedor digitado'])
            ->assertOk()
            ->assertSee('data-dialog-autoshow', false)
            ->assertSee('A quantidade deve ser maior que zero.')
            ->assertSee('value="Fornecedor digitado"', false);

        $this->assertSame(0, ProductBatch::count());
    }

    public function test_movements_tab_identifies_type_user_date_and_reason(): void
    {
        [$batch, $treatment] = $this->batchWithEveryKindOfMovement();
        $treatmentUrl = route('patients.treatments.show', [$treatment->patient_id, $treatment]);

        $this->actingAs($this->admin);

        $this->get('/estoque/movimentacoes')
            ->assertOk()
            ->assertSee('5 movimentações')
            // Do mais recente ao mais antigo.
            ->assertSeeInOrder(['Atendimento nº '.$treatment->id, 'Conferência mensal', 'Frasco quebrado', 'Devolução ao fornecedor', 'NF 77 · Distribuidora Alfa · Compra do mês'])
            ->assertSee('Dra. Helena')->assertSee('href="'.$treatmentUrl.'"', false)
            ->assertSee('08/10/2026 13:00')->assertSee('Ana Admin')
            ->assertSee('+100 U')->assertSee('−30 U')->assertSee('−3 U')
            // O paciente do atendimento não aparece no estoque.
            ->assertDontSee('Paciente Sigiloso');

        foreach (['entry' => 'Compra do mês', 'exit' => 'Devolução ao fornecedor', 'loss' => 'Frasco quebrado', 'adjustment' => 'Conferência mensal'] as $type => $reason) {
            $response = $this->get("/estoque/movimentacoes?tipo={$type}")->assertSee('1 movimentação')->assertSee($reason);

            foreach (array_diff(['Compra do mês', 'Devolução ao fornecedor', 'Frasco quebrado', 'Conferência mensal'], [$reason]) as $other) {
                $response->assertDontSee($other);
            }
        }

        $this->get('/estoque/movimentacoes?tipo=consumption')->assertSee('Atendimento nº '.$treatment->id)->assertDontSee('Frasco quebrado');
        $this->get('/estoque/movimentacoes?q=TX1')->assertSee('5 movimentações');
        $this->get('/estoque/movimentacoes?q=outro-lote')->assertSee('Nenhuma movimentação encontrada.');
        $this->get('/estoque/movimentacoes?de=2026-10-09')->assertSee('Nenhuma movimentação encontrada.');
        $this->get('/estoque/movimentacoes?tipo=roubo')->assertSessionHasErrors('tipo');

        $this->assertSame('60.000', $batch->refresh()->quantity);
    }

    public function test_batches_tab_orders_by_expiry_and_filters_by_situation(): void
    {
        $this->batch('Preenchedor', 'L-TARDE', 5, '2028-01-01');
        $this->batch('Preenchedor', 'L-SEMVAL', 5, null);
        $this->batch('Preenchedor', 'L-CEDO', 5, '2026-12-01');
        $this->batch('Anestésico', 'L-VENCIDO', 3, '2026-10-01');
        $this->batch('Anestésico', 'L-ZERADO', 0, '2027-01-01');

        $this->actingAs($this->admin);

        // Vence primeiro, aparece primeiro; sem validade fica por último. Esgotados ficam fora.
        $this->get('/estoque/lotes')
            ->assertOk()
            ->assertSee('4 lotes')
            ->assertSeeInOrder(['L-VENCIDO', 'L-CEDO', 'L-TARDE', 'L-SEMVAL'])
            ->assertSee('Vencido')->assertSee('Vence em 54 dias')->assertSee('Sem validade')
            ->assertDontSee('L-ZERADO');

        $this->get('/estoque/lotes?situacao=vencidos')->assertSee('L-VENCIDO')->assertDontSee('L-CEDO');
        $this->get('/estoque/lotes?situacao=vencendo')->assertSee('L-CEDO')->assertDontSee('L-VENCIDO')->assertDontSee('L-TARDE');
        $this->get('/estoque/lotes?situacao=esgotados')->assertSee('L-ZERADO')->assertDontSee('L-CEDO');
        $this->get('/estoque/lotes?situacao=todos')->assertSee('5 lotes');
        $this->get('/estoque/lotes?q=anest')->assertSee('L-VENCIDO')->assertDontSee('L-CEDO');
        $this->get('/estoque/lotes?q=L-TAR')->assertSee('L-TARDE')->assertDontSee('L-CEDO');
        $this->get('/estoque/lotes?situacao=x')->assertSessionHasErrors('situacao');
    }

    public function test_batch_page_traces_where_the_batch_came_from_and_where_it_was_used(): void
    {
        [$batch, $treatment] = $this->batchWithEveryKindOfMovement();
        $treatmentUrl = route('patients.treatments.show', [$treatment->patient_id, $treatment]);

        $this->actingAs($this->admin)->get("/estoque/lotes/{$batch->id}")
            ->assertOk()
            ->assertSee('Lote TX1')->assertSee('Toxina 100 U')->assertSee('30/06/2027')
            ->assertSee('60 U')                                    // saldo
            ->assertSeeInOrder(['Usado em atendimentos', '30 U'])
            ->assertSee('Entradas: 100 U · Saídas: 5 U · Perdas: 2 U')
            ->assertSee('NF 77 · Distribuidora Alfa · Compra do mês')
            ->assertSee('Atendimento nº '.$treatment->id)->assertSee('Dra. Helena')->assertSee('href="'.$treatmentUrl.'"', false)
            ->assertDontSee('Paciente Sigiloso');

        // Quem vê o estoque mas não o prontuário acompanha o rastro sem o link do atendimento.
        app(RolePermissions::class)->sync(UserRole::Receptionist, [Permission::StockView]);

        $this->actingAs(User::factory()->create())->get("/estoque/lotes/{$batch->id}")
            ->assertOk()
            ->assertSee('Atendimento nº '.$treatment->id)->assertSee('Dra. Helena')
            ->assertDontSee($treatmentUrl, false)
            ->assertDontSee('<dialog', false);

        $this->get('/estoque/lotes/999999')->assertNotFound();
    }

    public function test_losses_have_their_own_type_and_expired_batches_only_leave_as_loss(): void
    {
        $batch = $this->batch('Anestésico', 'VENC', 10, '2026-10-01');

        $this->actingAs($this->admin);

        $this->move($batch, 'loss', '1', null)->assertSessionHasErrors(['reason' => 'Informe o motivo.']);
        $this->move($batch, 'exit', '1', 'Empréstimo')->assertSessionHasErrors(['product_batch_id' => 'Este lote está vencido. Registre a baixa como perda.']);
        $this->move($batch, 'loss', '11', 'Descarte')->assertSessionHasErrors('quantity');

        $this->assertSame(0, StockMovement::count());

        $this->move($batch, 'loss', '10', 'Descarte por vencimento')->assertSessionHas('success', 'Perda registrada.');

        $movement = StockMovement::sole();

        $this->assertSame([StockMovementType::Loss, '-10.000', 'Descarte por vencimento', $this->admin->id], [$movement->type, $movement->quantity, $movement->reason, $movement->user_id]);
        $this->assertSame('0.000', $batch->refresh()->quantity);
    }

    public function test_adjustments_and_inventory_require_their_own_authorization(): void
    {
        $batch = $this->batch('Agulha 30G', 'AG1', 10);
        $permissions = app(RolePermissions::class);

        // Pode movimentar, mas não ajustar.
        $permissions->sync(UserRole::Professional, [Permission::StockManage]);
        $this->actingAs(User::factory()->professional()->create());

        $this->get("/estoque/{$batch->product_id}")
            ->assertOk()
            ->assertSee('data-dialog-open="stock-exit"', false)->assertSee('data-dialog-open="stock-loss"', false)
            ->assertDontSee('stock-adjustment', false);
        $this->get('/estoque/lotes')->assertOk()->assertDontSee('Inventário');

        $this->move($batch, 'adjustment', '8')->assertForbidden();
        $this->get('/estoque/inventario')->assertForbidden();
        $this->put('/estoque/inventario', ['reason' => 'Inventário', 'counted' => [$batch->id => '8']])->assertForbidden();
        $this->assertSame('10.000', $batch->refresh()->quantity);

        $this->move($batch, 'exit', '1', 'Uso interno')->assertSessionHasNoErrors();

        // A autorização de ajuste traz junto a de movimentar e a de ver.
        $permissions->sync(UserRole::Professional, [Permission::StockAdjust]);

        $this->assertEqualsCanonicalizing([Permission::StockView, Permission::StockManage, Permission::StockAdjust], $permissions->for(UserRole::Professional));

        $this->actingAs(User::factory()->professional()->create());

        $this->get("/estoque/{$batch->product_id}")->assertSee('data-dialog-open="stock-adjustment"', false);
        $this->get('/estoque/lotes')->assertSee('Inventário');
        $this->move($batch, 'adjustment', '8', 'Recontagem')->assertSessionHas('success', 'Ajuste registrado.');

        $this->assertSame('8.000', $batch->refresh()->quantity);
    }

    public function test_inventory_adjusts_only_the_counted_batches_that_differ(): void
    {
        $different = $this->batch('Agulha 30G', 'AG1', 10);
        $matching = $this->batch('Agulha 30G', 'AG2', 5);
        $notCounted = $this->batch('Seringa 1 mL', 'SE1', 8);
        $empty = $this->batch('Seringa 1 mL', 'SE0', 0);

        $this->actingAs($this->admin);

        $this->get('/estoque/inventario')
            ->assertOk()
            ->assertSeeInOrder(['AG1', 'AG2', 'SE1'])
            ->assertSee('name="counted['.$different->id.']"', false)
            ->assertSee('Inventário de 08/10/2026')
            // Lote sem saldo no sistema não entra na conferência.
            ->assertDontSee('SE0');
        $this->get('/estoque/inventario?q=seringa')->assertSee('SE1')->assertDontSee('AG1');

        $counted = [$different->id => '7,5', $matching->id => '5', $notCounted->id => ''];

        $this->put('/estoque/inventario', ['counted' => $counted])->assertSessionHasErrors(['reason' => 'Informe o motivo do inventário.']);
        $this->put('/estoque/inventario', ['reason' => 'Inventário', 'counted' => [$different->id => '']])->assertSessionHasErrors('counted');
        $this->put('/estoque/inventario', ['reason' => 'Inventário', 'counted' => [$different->id => '-1']])->assertSessionHasErrors("counted.{$different->id}");
        $this->put('/estoque/inventario', ['reason' => 'Inventário', 'counted' => [$different->id => '7', 999999 => '1']])->assertSessionHasErrors('counted');

        $this->assertSame(0, StockMovement::count());

        $this->put('/estoque/inventario', ['reason' => 'Inventário de outubro', 'counted' => $counted])
            ->assertSessionHas('success', 'Inventário registrado: 1 lote ajustado e 1 confere com o saldo.');

        $movement = StockMovement::sole();

        $this->assertSame([StockMovementType::Adjustment, $different->id, '-2.500', 'Inventário de outubro', $this->admin->id], [
            $movement->type, $movement->product_batch_id, $movement->quantity, $movement->reason, $movement->user_id,
        ]);
        $this->assertSame(['7.500', '5.000', '8.000', '0.000'], array_map(fn (ProductBatch $batch) => $batch->refresh()->quantity, [$different, $matching, $notCounted, $empty]));

        $this->put('/estoque/inventario', ['reason' => 'Recontagem', 'counted' => [$matching->id => '5']])
            ->assertSessionHas('success', 'Inventário conferido: todas as contagens batem com o saldo.');
        $this->assertSame(1, StockMovement::count());
    }

    public function test_products_have_a_unique_code_that_can_be_searched(): void
    {
        Product::factory()->create(['name' => 'Agulha 30G', 'code' => 'AG-30']);
        Product::factory()->create(['name' => 'Seringa 1 mL', 'code' => null]);
        $payload = ['name' => 'Agulha 27G', 'category' => 'disposable', 'unit' => 'un', 'minimum_stock' => '0', 'is_active' => '1'];

        $this->actingAs($this->admin);

        $this->post('/estoque', [...$payload, 'code' => 'AG-30'])->assertSessionHasErrors(['code' => 'Já existe um produto com este código.']);
        $this->post('/estoque', [...$payload, 'code' => str_repeat('9', 41)])->assertSessionHasErrors('code');
        $this->post('/estoque', [...$payload, 'code' => 'AG-27'])->assertSessionHasNoErrors();
        // Sem código é permitido, mais de uma vez.
        $this->post('/estoque', [...$payload, 'name' => 'Luva M', 'code' => ''])->assertSessionHasNoErrors();

        $this->get('/estoque?q=AG-27')->assertSee('Agulha 27G')->assertDontSee('Seringa 1 mL')->assertDontSee('Agulha 30G');
        $this->get('/estoque?q=agulha')->assertSee('AG-27')->assertSee('AG-30');
        $this->get('/estoque/'.Product::where('code', 'AG-27')->sole()->id)->assertSee('código AG-27');
    }

    public function test_new_products_are_registered_in_a_dialog_on_the_products_tab(): void
    {
        Product::factory()->create(['name' => 'Agulha 30G']);

        $this->actingAs($this->admin);

        $html = $this->get('/estoque')
            ->assertOk()
            ->assertSee('data-dialog-open="product-create"', false)
            ->assertSee('<form method="POST" action="'.route('products.store').'"', false)
            ->getContent();

        // Cadastro e edição convivem na página sem repetir ids.
        foreach (['product-create-name', 'product-edit-name'] as $id) {
            $this->assertSame(1, substr_count($html, 'id="'.$id.'"'), $id);
        }

        $reopened = $this->followingRedirects()->from('/estoque')
            ->post('/estoque', ['_dialog' => 'product-create', 'name' => '', 'category' => 'disposable', 'unit' => 'un', 'minimum_stock' => '0', 'brand' => 'Marca digitada'])
            ->assertOk()
            ->assertSee('O campo nome é obrigatório.')
            ->getContent();

        $this->assertMatchesRegularExpression('/<dialog id="product-create"[^>]*data-dialog-autoshow/', $reopened);
        $this->assertDoesNotMatchRegularExpression('/<dialog id="product-edit"[^>]*data-dialog-autoshow/', $reopened);
        // O que foi digitado volta só para o diálogo de cadastro.
        $this->assertSame(1, substr_count($reopened, 'value="Marca digitada"'));
        $this->assertSame(1, Product::count());
    }

    public function test_product_and_stock_changes_are_audited_without_values(): void
    {
        $this->actingAs($this->admin);

        $this->post('/estoque', ['name' => 'Agulha 30G', 'category' => 'disposable', 'unit' => 'un', 'minimum_stock' => '0', 'is_active' => '1', 'supplier' => 'Fornecedor Secreto']);
        $product = Product::sole();

        $this->put("/estoque/{$product->id}", ['name' => 'Agulha 30G', 'category' => 'disposable', 'unit' => 'un', 'minimum_stock' => '5', 'is_active' => '1', 'supplier' => 'Outro Fornecedor']);
        // Salvar sem mudar nada não gera registro.
        $this->put("/estoque/{$product->id}", ['name' => 'Agulha 30G', 'category' => 'disposable', 'unit' => 'un', 'minimum_stock' => '5', 'is_active' => '1', 'supplier' => 'Outro Fornecedor']);

        $this->entry($product, ['batch_code' => 'AG1', 'quantity' => '10', 'supplier' => 'Outro Fornecedor', 'invoice_number' => 'NF-1']);
        $batch = ProductBatch::sole();
        $this->move($batch, 'loss', '1', 'Quebra');

        $logs = AuditLog::whereIn('action', [AuditAction::ProductCreated->value, AuditAction::ProductUpdated->value, AuditAction::StockMoved->value])->orderBy('id')->get();

        $this->assertSame(
            [AuditAction::ProductCreated, AuditAction::ProductUpdated, AuditAction::StockMoved, AuditAction::StockMoved],
            $logs->pluck('action')->all(),
        );
        $this->assertEqualsCanonicalizing(['minimum_stock', 'supplier'], $logs[1]->metadata['fields']);
        $this->assertSame(['type' => 'entry', 'product_id' => $product->id, 'product_batch_id' => $batch->id], $logs[2]->metadata);
        $this->assertSame('loss', $logs[3]->metadata['type']);
        $this->assertSame([$this->admin->id], $logs->pluck('user_id')->unique()->all());
        $this->assertSame(StockMovement::orderBy('id')->pluck('id')->all(), [$logs[2]->auditable_id, $logs[3]->auditable_id]);

        // Na auditoria entram nomes de campos e identificadores, nunca os valores.
        $this->assertStringNotContainsString('Fornecedor', $logs->toJson());
        $this->assertStringNotContainsString('NF-1', $logs->toJson());
    }

    public function test_tabs_run_a_fixed_number_of_queries(): void
    {
        $ledger = app(StockLedger::class);

        foreach (range(1, 16) as $index) {
            $batch = $this->batch("Produto {$index}", "L{$index}", 0);
            $ledger->enter($batch, '10', null, $this->admin, ['invoice_number' => "NF-{$index}"]);
            $ledger->consume($batch, '1', Treatment::factory()->finalized()->create(), $this->admin);
        }

        $this->actingAs($this->admin);

        // Sessão e permissões, alertas, contagem e página, mais uma consulta por relação exibida.
        foreach (['/estoque/entradas' => 9, '/estoque/movimentacoes' => 10, '/estoque/lotes' => 6, '/estoque/inventario' => 5] as $url => $limit) {
            DB::flushQueryLog();
            DB::enableQueryLog();

            $this->get($url)->assertOk();

            $this->assertLessThanOrEqual($limit, count(DB::getQueryLog()), $url);
        }
    }

    public function test_output_is_escaped_in_every_stock_screen(): void
    {
        $batch = $this->batch('<script>alert(1)</script>', '"><script>alert(2)</script>', 0);
        app(StockLedger::class)->enter($batch, '10', '<img src=x onerror=alert(3)>', $this->admin, [
            'supplier' => '<b onmouseover=alert(4)>fornecedor</b>', 'invoice_number' => '<i>nf</i>',
        ]);

        $this->actingAs($this->admin);

        foreach ([...self::TABS, "/estoque/lotes/{$batch->id}", '/estoque/inventario'] as $url) {
            $this->get($url)
                ->assertOk()
                ->assertDontSee('<script>alert(1)</script>', false)
                ->assertDontSee('<script>alert(2)</script>', false)
                ->assertDontSee('<img src=x onerror=alert(3)>', false)
                ->assertDontSee('<b onmouseover=alert(4)>', false)
                ->assertDontSee('<i>nf</i>', false);
        }
    }
}
