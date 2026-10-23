<?php

namespace Tests\Feature;

use App\Enums\AuditAction;
use App\Enums\ProductCategory;
use App\Enums\ProductUnit;
use App\Enums\StockMovementType;
use App\Models\AuditLog;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\StockInvoice;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\InvoiceXmlReader;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Importação de nota fiscal (XML da NF-e) para cadastrar produtos e lançar entradas.
 */
class StockInvoiceImportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    /** Token da última nota enviada, que identifica a conferência na sessão. */
    private string $token = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-08 13:00', 'America/Sao_Paulo'));
        $this->admin = User::factory()->admin()->create(['name' => 'Ana Admin']);
    }

    /**
     * Item de nota: produto e, opcionalmente, os lotes do grupo de rastreabilidade.
     *
     * @param  list<array{string, string, string, string}>  $lots  Lote, quantidade, fabricação e validade.
     */
    private function item(string $name, string $code, string $unit, string $quantity, string $unitCost, array $lots = [], string $ncm = '30049099', string $barcode = 'SEM GTIN'): string
    {
        $traces = implode('', array_map(
            fn (array $lot) => '<rastro><nLote>'.htmlspecialchars($lot[0], ENT_XML1)."</nLote><qLote>{$lot[1]}</qLote><dFab>{$lot[2]}</dFab><dVal>{$lot[3]}</dVal></rastro>",
            $lots,
        ));

        return '<det nItem="1"><prod>'
            .'<cProd>'.htmlspecialchars($code, ENT_XML1)."</cProd><cEAN>{$barcode}</cEAN><xProd>".htmlspecialchars($name, ENT_XML1)."</xProd><NCM>{$ncm}</NCM>"
            ."<uCom>{$unit}</uCom><qCom>{$quantity}</qCom><vUnCom>{$unitCost}</vUnCom>{$traces}"
            .'</prod></det>';
    }

    /**
     * XML de NF-e no formato distribuído pelo fornecedor (nfeProc), com o namespace oficial.
     *
     * @param  list<string>  $items
     */
    private function invoiceXml(array $items, string $number = '4521', string $supplier = 'Distribuidora Alfa Ltda', ?string $accessKey = '35261012345678000199550010000045211000045210'): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<nfeProc xmlns="http://www.portalfiscal.inf.br/nfe" versao="4.00"><NFe><infNFe'.($accessKey === null ? '' : ' Id="NFe'.$accessKey.'"').' versao="4.00">'
            ."<ide><nNF>{$number}</nNF><serie>1</serie><dhEmi>2026-10-01T10:15:00-03:00</dhEmi></ide>"
            .'<emit><CNPJ>12345678000199</CNPJ><xNome>'.htmlspecialchars($supplier, ENT_XML1).'</xNome></emit>'
            .implode('', $items)
            .'</infNFe></NFe></nfeProc>';
    }

    private function upload(string $contents, string $name = 'nota.xml'): TestResponse
    {
        $response = $this->post('/estoque/nota-fiscal', ['_dialog' => 'invoice-import', 'invoice' => $this->realUpload($name, $contents, 'text/xml')]);
        parse_str((string) parse_url((string) $response->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->token = (string) ($query['importacao'] ?? '');

        return $response;
    }

    private function reviewUrl(?string $token = null): string
    {
        return '/estoque/nota-fiscal?importacao='.($token ?? $this->token);
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    private function confirm(array $items, ?string $token = null): TestResponse
    {
        return $this->put('/estoque/nota-fiscal', ['importacao' => $token ?? $this->token, 'items' => $items]);
    }

    /**
     * Nota com um item rastreado em dois lotes e um item sem lote.
     */
    private function sampleInvoice(): string
    {
        return $this->invoiceXml([
            $this->item('TOXINA BOTULINICA 100U', 'TX-100', 'FR', '3.0000', '950.5000000000', [
                ['L2401', '2.000', '2026-05-10', '2027-05-10'],
                ['L2402', '1.000', '2026-06-15', '2027-06-15'],
            ], '30021590'),
            $this->item('AGULHA 30G 13MM', 'AG-30', 'CX', '10.0000', '18.90', [], '90183219', '7891234567895'),
        ]);
    }

    /**
     * Campos do formulário de conferência como a tela os envia: tudo o que foi lido, sem alterações.
     *
     * @return array<int, array<string, mixed>>
     */
    private function reviewedItems(array $overrides = []): array
    {
        $items = [
            [
                'import' => '1', 'product_id' => '', 'name' => 'Toxina botulínica 100 U', 'code' => 'TX-100', 'category' => 'injectable', 'unit' => 'fr',
                'total_cost' => '2.851,50', 'enter' => '1',
                'lots' => [
                    ['batch_code' => 'L2401', 'quantity' => '2', 'manufactured_on' => '2026-05-10', 'expires_on' => '2027-05-10'],
                    ['batch_code' => 'L2402', 'quantity' => '1', 'manufactured_on' => '2026-06-15', 'expires_on' => '2027-06-15'],
                ],
            ],
            [
                'import' => '1', 'product_id' => '', 'name' => 'Agulha 30G 13 mm', 'code' => '7891234567895', 'category' => 'disposable', 'unit' => 'cx',
                'total_cost' => '189,00', 'enter' => '0',
                'lots' => [['batch_code' => '', 'quantity' => '10', 'manufactured_on' => '', 'expires_on' => '']],
            ],
        ];

        foreach ($overrides as $index => $changes) {
            $items[$index] = array_replace_recursive($items[$index], $changes);
        }

        return $items;
    }

    public function test_import_follows_the_stock_permissions(): void
    {
        $this->post('/estoque/nota-fiscal')->assertRedirect('/login');
        $this->get('/estoque/nota-fiscal')->assertRedirect('/login');

        // Profissional consulta o estoque, mas não cadastra nem lança entradas.
        $this->actingAs(User::factory()->professional()->create());

        $this->get('/estoque')->assertOk()->assertDontSee('Importar nota fiscal');
        $this->get('/estoque/entradas')->assertOk()->assertDontSee('Importar nota fiscal');
        $this->upload($this->sampleInvoice())->assertForbidden();
        $this->get('/estoque/nota-fiscal')->assertForbidden();
        $this->put('/estoque/nota-fiscal', ['items' => $this->reviewedItems()])->assertForbidden();
        $this->delete('/estoque/nota-fiscal')->assertForbidden();

        $this->assertSame(0, Product::count());
    }

    public function test_products_and_entries_tabs_offer_the_import(): void
    {
        $this->actingAs($this->admin);

        foreach (['/estoque', '/estoque/entradas'] as $url) {
            $this->get($url)
                ->assertOk()
                ->assertSee('data-dialog-open="invoice-import"', false)
                ->assertSee('<form method="POST" action="'.route('stock.invoice.store').'" enctype="multipart/form-data"', false);
        }

        // Sem nota enviada não há o que conferir.
        $this->get('/estoque/nota-fiscal')->assertRedirect(route('products.index'))->assertSessionHas('warning');
    }

    public function test_uploaded_invoice_is_read_and_shown_for_review(): void
    {
        $existing = Product::factory()->create(['name' => 'Agulha 30G curta', 'code' => '7891234567895', 'unit' => ProductUnit::Box]);

        $this->actingAs($this->admin);

        $this->upload($this->sampleInvoice())->assertRedirect(route('stock.invoice.edit', ['importacao' => $this->token]))->assertSessionHasNoErrors();
        $this->assertSame(32, strlen($this->token));

        $this->get($this->reviewUrl())
            ->assertOk()
            ->assertSee('NF nº 4521 · série 1 · emitida em 01/10/2026 · Distribuidora Alfa Ltda')
            ->assertSee('1. TOXINA BOTULINICA 100U')->assertSee('Na nota: 3 FR × R$ 950,50')->assertSee('= R$ 2.851,50')
            ->assertSee('value="2851,50"', false)
            ->assertSee('2. AGULHA 30G 13MM')->assertSee('Na nota: 10 CX × R$ 18,90')
            // Lotes do grupo de rastreabilidade, um por linha.
            ->assertSee('value="L2401"', false)->assertSee('value="2027-05-10"', false)->assertSee('value="L2402"', false)
            // Unidade e categoria sugeridas pela unidade comercial e pelo NCM.
            ->assertSee('<option value="fr" selected>', false)->assertSee('<option value="injectable" selected>', false)
            // O item com código de barras já cadastrado vem ligado ao produto existente.
            ->assertSee('<option value="'.$existing->id.'" selected>', false)
            ->assertSee('Este item já está cadastrado')
            // Sem lote na nota, a entrada daquele item não vem marcada.
            ->assertSee('A nota não informa o lote deste item.')
            ->assertDontSee('Já existem entradas lançadas');

        // Ler a nota não grava nada.
        $this->assertSame(1, Product::count());
        $this->assertSame(0, StockMovement::count());
    }

    public function test_confirming_registers_new_products_and_records_the_entries(): void
    {
        $this->actingAs($this->admin);
        $this->upload($this->sampleInvoice());

        $this->confirm($this->reviewedItems())
            ->assertRedirect(route('stock.entries.index'))
            ->assertSessionHas('success', 'Nota fiscal importada: 2 produtos cadastrados e 2 entradas lançadas. Complete o cadastro (estoque mínimo, marca) de: Toxina botulínica 100 U, Agulha 30G 13 mm.');

        $toxin = Product::where('name', 'Toxina botulínica 100 U')->sole();
        // Um atalho por produto novo, para completar o cadastro.
        $this->assertSame([route('products.edit', $toxin)], array_column(array_slice(session('success_links'), 0, 1), 'url'));
        $needle = Product::where('name', 'Agulha 30G 13 mm')->sole();

        $this->assertSame(
            ['TX-100', ProductCategory::Injectable, ProductUnit::Vial, '950.5000', 'Distribuidora Alfa Ltda', true],
            [$toxin->code, $toxin->category, $toxin->unit, $toxin->cost, $toxin->supplier, $toxin->is_active],
        );
        $this->assertSame(['7891234567895', ProductUnit::Box, '18.9000'], [$needle->code, $needle->unit, $needle->cost]);

        // Um lote por linha de rastreabilidade, com fabricação e validade.
        $batches = ProductBatch::orderBy('batch_code')->get();
        $this->assertSame(
            [['L2401', '2.000', '2026-05-10', '2027-05-10'], ['L2402', '1.000', '2026-06-15', '2027-06-15']],
            $batches->map(fn (ProductBatch $batch) => [$batch->batch_code, $batch->quantity, $batch->manufactured_on->format('Y-m-d'), $batch->expires_on->format('Y-m-d')])->all(),
        );
        $this->assertSame([$toxin->id], $batches->pluck('product_id')->unique()->all());

        // As entradas levam os dados da compra e quem importou. O custo é o
        // valor total do item dividido pela quantidade lançada.
        $movements = StockMovement::orderBy('id')->get();
        $this->assertSame(
            [[StockMovementType::Entry, '2.000', '950.5000', 'Distribuidora Alfa Ltda', '4521', $this->admin->id], [StockMovementType::Entry, '1.000', '950.5000', 'Distribuidora Alfa Ltda', '4521', $this->admin->id]],
            $movements->map(fn (StockMovement $movement) => [$movement->type, $movement->quantity, $movement->unit_cost, $movement->supplier, $movement->invoice_number, $movement->user_id])->all(),
        );

        $this->assertSame(2, AuditLog::where('action', AuditAction::ProductCreated->value)->count());
        $this->assertSame(2, AuditLog::where('action', AuditAction::StockMoved->value)->count());

        // A importação termina ali: a tela de conferência não fica disponível,
        // e a nota fica registrada pela chave de acesso.
        $this->get($this->reviewUrl())->assertRedirect(route('products.index'));
        $this->assertSame(['35261012345678000199550010000045211000045210'], StockInvoice::pluck('access_key')->all());
        $this->get('/estoque/entradas')->assertSee('L2401')->assertSee('4521')->assertSee('Distribuidora Alfa Ltda');
    }

    public function test_existing_products_receive_the_entry_instead_of_being_duplicated(): void
    {
        $toxin = Product::factory()->create(['name' => 'Toxina botulínica 100 U', 'code' => 'TX-100', 'cost' => 800, 'supplier' => 'Fornecedor antigo']);
        ProductBatch::factory()->create(['product_id' => $toxin->id, 'batch_code' => 'L2401', 'quantity' => 4, 'manufactured_on' => '2026-05-10', 'expires_on' => '2027-05-10']);

        $this->actingAs($this->admin);
        $this->upload($this->sampleInvoice());

        $this->confirm($this->reviewedItems([
            // Os dados de cadastro digitados são ignorados quando o produto já existe.
            0 => ['product_id' => (string) $toxin->id, 'name' => 'Outro nome qualquer', 'code' => 'OUTRO'],
            1 => ['import' => '0'],
        ]))->assertSessionHas('success', 'Nota fiscal importada: 0 produtos cadastrados e 2 entradas lançadas.');

        $this->assertSame(1, Product::count());
        $this->assertSame(['Toxina botulínica 100 U', 'TX-100', '800.0000', 'Fornecedor antigo'], [$toxin->refresh()->name, $toxin->code, $toxin->cost, $toxin->supplier]);
        // O lote que já existia soma; o novo é criado.
        $this->assertSame(['L2401' => '6.000', 'L2402' => '1.000'], ProductBatch::orderBy('batch_code')->pluck('quantity', 'batch_code')->all());
    }

    public function test_items_can_be_registered_without_stock_entry_and_two_items_with_the_same_name_become_one_product(): void
    {
        $this->actingAs($this->admin);
        $this->upload($this->invoiceXml([
            $this->item('LUVA NITRILICA M', 'LV-M', 'CX', '2', '45.00', [['LV01', '2', '2026-01-10', '2029-01-10']]),
            $this->item('LUVA NITRILICA M', 'LV-M', 'CX', '3', '45.00', [['LV02', '3', '2026-02-10', '2029-02-10']]),
            $this->item('GAZE ESTERIL', 'GZ-1', 'UN', '50', '0.80'),
        ]));

        $lot = fn (string $code, string $quantity, string $expiresOn) => [['batch_code' => $code, 'quantity' => $quantity, 'manufactured_on' => '', 'expires_on' => $expiresOn]];
        $glove = ['import' => '1', 'product_id' => '', 'name' => 'Luva nitrílica M', 'code' => 'LV-M', 'category' => 'disposable', 'unit' => 'cx', 'total_cost' => '90', 'enter' => '1'];

        $this->confirm([
            [...$glove, 'lots' => $lot('LV01', '200', '2029-01-10')],   // quantidade convertida para a unidade do estoque
            [...$glove, 'lots' => $lot('LV02', '300', '2029-02-10')],
            ['import' => '1', 'product_id' => '', 'name' => 'Gaze estéril', 'code' => '', 'category' => 'disposable', 'unit' => 'un', 'total_cost' => '', 'enter' => '0', 'lots' => $lot('', '50', '')],
        ])
            ->assertRedirect(route('stock.entries.index'))
            ->assertSessionHas('success', 'Nota fiscal importada: 2 produtos cadastrados e 2 entradas lançadas. Complete o cadastro (estoque mínimo, marca) de: Luva nitrílica M, Gaze estéril.');

        $this->assertSame(['Gaze estéril', 'Luva nitrílica M'], Product::orderBy('name')->pluck('name')->all());
        $this->assertSame(['LV01' => '200.000', 'LV02' => '300.000'], ProductBatch::orderBy('batch_code')->pluck('quantity', 'batch_code')->all());
        $this->assertNull(Product::where('name', 'Gaze estéril')->sole()->code);
    }

    public function test_review_is_validated_and_nothing_is_saved_when_an_item_fails(): void
    {
        Product::factory()->create(['name' => 'Já Existe', 'code' => 'JA-1']);
        $inactive = Product::factory()->create(['is_active' => false]);

        $this->actingAs($this->admin);
        $this->upload($this->sampleInvoice());

        $attempt = fn (array $overrides) => $this->from($this->reviewUrl())->confirm($this->reviewedItems($overrides));

        $attempt([0 => ['name' => '']])->assertSessionHasErrors('items.0.name');
        $attempt([0 => ['name' => 'Já Existe']])->assertSessionHasErrors(['items.0.name' => 'Já existe um produto com este nome. Escolha-o na lista de produtos ou, se ele estiver inativo, reative-o no cadastro.']);
        $attempt([0 => ['code' => 'JA-1']])->assertSessionHasErrors('items.0.code');
        $attempt([0 => ['category' => 'remedio']])->assertSessionHasErrors('items.0.category');
        $attempt([0 => ['unit' => 'litro']])->assertSessionHasErrors('items.0.unit');
        $attempt([0 => ['total_cost' => '-1']])->assertSessionHasErrors('items.0.total_cost');
        $attempt([0 => ['product_id' => (string) $inactive->id]])->assertSessionHasErrors('items.0.product_id');
        $attempt([0 => ['product_id' => '999999']])->assertSessionHasErrors('items.0.product_id');
        $attempt([0 => ['lots' => [1 => ['quantity' => '0']]]])->assertSessionHasErrors('items.0.lots.1.quantity');
        $attempt([0 => ['lots' => [0 => ['batch_code' => '']]]])->assertSessionHasErrors('items.0.lots.0.batch_code');
        // Lote vencido não entra, e a validade não pode ser anterior à fabricação.
        $attempt([0 => ['lots' => [0 => ['expires_on' => '2026-10-07']]]])->assertSessionHasErrors('items.0.lots.0.expires_on');
        $attempt([0 => ['lots' => [0 => ['manufactured_on' => '2026-10-09']]]])->assertSessionHasErrors('items.0.lots.0.manufactured_on');
        // Pedir a entrada de um item sem lote é recusado.
        $attempt([1 => ['enter' => '1']])->assertSessionHasErrors(['items.1.lots.0.batch_code' => 'Informe o lote, ou desmarque a entrada de estoque deste item.']);
        $attempt([0 => ['import' => '0'], 1 => ['import' => '0']])->assertSessionHasErrors(['items' => 'Marque pelo menos um item da nota para importar.']);
        $this->put('/estoque/nota-fiscal', [])->assertSessionHasErrors(['items', 'importacao']);

        // Item desmarcado não é validado nem gravado.
        $attempt([0 => ['import' => '0', 'name' => '', 'lots' => [0 => ['quantity' => 'abc']]]])->assertSessionHasNoErrors();

        $this->assertSame(['Agulha 30G 13 mm'], Product::whereNotIn('name', ['Já Existe', $inactive->name])->pluck('name')->all());
        $this->assertSame(0, StockMovement::count());
    }

    public function test_a_failure_in_one_item_rolls_back_the_whole_invoice_and_points_to_the_field(): void
    {
        $toxin = Product::factory()->create(['name' => 'Toxina botulínica 100 U']);
        // O lote já existe com outra validade: a entrada do segundo lote da nota vai falhar.
        ProductBatch::factory()->create(['product_id' => $toxin->id, 'batch_code' => 'L2402', 'quantity' => 1, 'manufactured_on' => null, 'expires_on' => '2028-01-01']);

        $this->actingAs($this->admin);
        $this->upload($this->sampleInvoice());

        $response = $this->followingRedirects()->from($this->reviewUrl())
            ->confirm($this->reviewedItems([0 => ['product_id' => (string) $toxin->id]]));

        $response->assertOk()
            ->assertSee('O lote L2402 já existe com fabricação não informada.')
            // O que foi digitado volta para a conferência.
            ->assertSee('value="Agulha 30G 13 mm"', false);

        // Nem o produto novo do outro item, nem a entrada do primeiro lote ficaram gravados.
        $this->assertSame(1, Product::count());
        $this->assertSame(0, StockMovement::count());
        $this->assertSame(0, ProductBatch::where('batch_code', 'L2401')->count());
    }

    public function test_the_same_invoice_cannot_be_imported_twice_and_the_review_can_be_cancelled(): void
    {
        $this->actingAs($this->admin);

        $this->upload($this->sampleInvoice());
        $this->confirm($this->reviewedItems())->assertSessionHasNoErrors();

        // A mesma nota de novo: a conferência avisa e não oferece a confirmação.
        $this->upload($this->sampleInvoice());
        $this->get($this->reviewUrl())->assertOk()
            ->assertSee('Esta nota fiscal já foi importada em 08/10/2026 13:00 por Ana Admin.')
            ->assertDontSee('Confirmar importação');

        // Mesmo enviada à força, a confirmação é recusada e nada é somado.
        $this->from($this->reviewUrl())->confirm($this->reviewedItems())->assertSessionHasErrors(['importacao' => 'Esta nota fiscal já foi importada. Nada foi lançado de novo.']);
        $this->assertSame(2, StockMovement::count());
        $this->assertSame(2, Product::count());

        // Token malformado não derruba a página nem cancela nada.
        $this->delete('/estoque/nota-fiscal', ['importacao' => ['x']])->assertRedirect(route('products.index'));
        $this->get($this->reviewUrl())->assertOk();
        $this->get('/estoque/nota-fiscal?importacao[]=x')->assertRedirect(route('products.index'));

        $this->delete('/estoque/nota-fiscal', ['importacao' => $this->token])->assertRedirect(route('products.index'))->assertSessionHas('success', 'Importação cancelada. Nada foi gravado.');
        $this->get($this->reviewUrl())->assertRedirect(route('products.index'));

        $this->assertSame(2, StockMovement::count());
    }

    public function test_an_invoice_without_access_key_only_warns_by_number_and_supplier(): void
    {
        $this->actingAs($this->admin);
        $invoice = $this->invoiceXml([$this->item('LUVA NITRILICA M', 'LV-M', 'CX', '2', '45.00', [['LV01', '2', '2026-01-10', '2029-01-10']])], accessKey: null);

        $this->upload($invoice);
        $this->confirm([['import' => '1', 'product_id' => '', 'name' => 'Luva', 'code' => 'LV-M', 'category' => 'disposable', 'unit' => 'cx', 'total_cost' => '90', 'enter' => '1',
            'lots' => [['batch_code' => 'LV01', 'quantity' => '2', 'manufactured_on' => '', 'expires_on' => '2029-01-10']]]])->assertSessionHasNoErrors();
        $this->assertSame(0, StockInvoice::count());

        $this->upload($invoice);
        $this->get($this->reviewUrl())->assertOk()
            ->assertSee('Já existem entradas lançadas com a nota nº 4521 deste fornecedor.')
            ->assertSee('Confirmar importação');
    }

    public function test_two_invoices_in_review_at_once_do_not_mix(): void
    {
        $this->actingAs($this->admin);

        $this->upload($this->invoiceXml([$this->item('LUVA NITRILICA M', 'LV-M', 'CX', '2', '45.00', [['LV01', '2', '2026-01-10', '2029-01-10']])], '1001', 'Fornecedor A', str_repeat('1', 44)));
        $first = $this->token;
        $this->upload($this->invoiceXml([$this->item('GAZE ESTERIL', 'GZ-1', 'UN', '50', '0.80', [['GZ01', '50', '2026-01-10', '2029-01-10']])], '2002', 'Fornecedor B', str_repeat('2', 44)));

        $this->get($this->reviewUrl($first))->assertOk()->assertSee('NF nº 1001')->assertSee('LUVA NITRILICA M')->assertDontSee('GAZE ESTERIL');
        $this->get($this->reviewUrl())->assertOk()->assertSee('NF nº 2002')->assertSee('GAZE ESTERIL');

        // Confirmar a primeira aba grava os dados da primeira nota.
        $this->confirm([['import' => '1', 'product_id' => '', 'name' => 'Luva', 'code' => 'LV-M', 'category' => 'disposable', 'unit' => 'cx', 'total_cost' => '90', 'enter' => '1',
            'lots' => [['batch_code' => 'LV01', 'quantity' => '2', 'manufactured_on' => '', 'expires_on' => '2029-01-10']]]], $first)->assertSessionHasNoErrors();

        $this->assertSame(['1001', 'Fornecedor A'], [StockMovement::sole()->invoice_number, StockMovement::sole()->supplier]);
        $this->assertSame([str_repeat('1', 44)], StockInvoice::pluck('access_key')->all());
        // A outra nota segue em conferência; a primeira não pode ser confirmada de novo.
        $this->get($this->reviewUrl())->assertOk();
        $this->confirm([], $first)->assertSessionHasErrors('items');
        $this->from($this->reviewUrl($first))->confirm([['import' => '1', 'product_id' => '', 'name' => 'Luva 2', 'code' => '', 'category' => 'disposable', 'unit' => 'cx', 'total_cost' => '', 'enter' => '0',
            'lots' => [['batch_code' => '', 'quantity' => '2', 'manufactured_on' => '', 'expires_on' => '']]]], $first)
            ->assertRedirect(route('products.index'))->assertSessionHas('warning');
        $this->assertSame(1, StockMovement::count());
    }

    public function test_fractioned_products_enter_from_the_invoice_in_whole_units_with_the_cost_per_stock_unit(): void
    {
        $toxin = Product::factory()->create(['name' => 'Toxina botulínica 100 U', 'code' => 'TX-100', 'unit' => ProductUnit::ToxinUnit, 'unit_content' => 100]);

        $this->actingAs($this->admin);
        $this->upload($this->sampleInvoice());
        $this->get($this->reviewUrl())->assertOk()->assertSee('Toxina botulínica 100 U · TX-100 (U, unidade de 100 U)');

        $items = fn (string $first, string $second) => $this->reviewedItems([
            0 => ['product_id' => (string) $toxin->id, 'lots' => [0 => ['quantity' => $first], 1 => ['quantity' => $second]]],
            1 => ['import' => '0'],
        ]);

        // Na unidade da nota (frascos) não forma unidades inteiras de 100 U.
        $this->from($this->reviewUrl())->confirm($items('2', '1'))->assertSessionHasErrors(['items.0.lots.0.quantity', 'items.0.lots.1.quantity']);
        $this->assertSame(0, StockMovement::count());

        $this->confirm($items('200', '100'))->assertSessionHasNoErrors();

        // R$ 2.851,50 por 300 U: R$ 9,505 por U, sem perder a fração de centavo.
        $this->assertSame(['9.5050', '9.5050'], StockMovement::orderBy('id')->pluck('unit_cost')->all());
        $this->assertSame(['L2401' => '200.000', 'L2402' => '100.000'], ProductBatch::orderBy('batch_code')->pluck('quantity', 'batch_code')->all());
    }

    public function test_an_inactive_product_with_the_same_name_is_pointed_out_in_the_review(): void
    {
        Product::factory()->create(['name' => 'TOXINA BOTULINICA 100U', 'is_active' => false]);

        $this->actingAs($this->admin);
        $this->upload($this->sampleInvoice());

        $this->get($this->reviewUrl())->assertOk()
            ->assertSee('Já existe o produto inativo "TOXINA BOTULINICA 100U" com este nome ou código.', false);
    }

    public function test_new_items_with_the_same_code_and_different_names_are_refused(): void
    {
        $this->actingAs($this->admin);
        $this->upload($this->sampleInvoice());

        $this->from($this->reviewUrl())->confirm($this->reviewedItems([1 => ['code' => 'TX-100']]))
            ->assertSessionHasErrors(['items.1.code' => 'Outro item novo desta nota já usa este código. Use códigos diferentes ou o mesmo nome.']);
        $this->assertSame(0, Product::count());
    }

    public function test_entries_into_an_expired_batch_are_refused_even_without_the_expiry_in_the_invoice(): void
    {
        $toxin = Product::factory()->create(['name' => 'Toxina botulínica 100 U']);
        ProductBatch::factory()->create(['product_id' => $toxin->id, 'batch_code' => 'L2401', 'quantity' => 0, 'manufactured_on' => '2026-05-10', 'expires_on' => '2026-09-30']);

        $this->actingAs($this->admin);
        $this->upload($this->sampleInvoice());

        $this->from($this->reviewUrl())->confirm($this->reviewedItems([
            0 => ['product_id' => (string) $toxin->id, 'lots' => [0 => ['manufactured_on' => '', 'expires_on' => '']]],
            1 => ['import' => '0'],
        ]))->assertSessionHasErrors(['items.0.lots.0.batch_code' => 'O lote L2401 venceu em 30/09/2026. Não é possível dar entrada em lote vencido.']);

        $this->assertSame(0, StockMovement::count());
    }

    public function test_files_that_are_not_an_invoice_are_refused(): void
    {
        $this->actingAs($this->admin);

        $refused = fn (TestResponse $response) => $response->assertSessionHasErrors('invoice');

        $refused($this->post('/estoque/nota-fiscal', []));
        $refused($this->upload('%PDF-1.7 danfe', 'danfe.pdf'));
        $refused($this->upload('isto não é xml'));
        $refused($this->upload('<?xml version="1.0"?><pedido><item>1</item></pedido>'));
        $refused($this->upload($this->invoiceXml([])));
        $refused($this->upload(str_replace('</nfeProc>', '', $this->sampleInvoice())));
        $refused($this->upload($this->invoiceXml(array_fill(0, InvoiceXmlReader::MAX_ITEMS + 1, $this->item('ITEM', 'I', 'UN', '1', '1')))));
        $refused($this->upload(str_repeat(' ', 1024 * 1024 + 1).$this->sampleInvoice()));

        $this->get('/estoque/nota-fiscal')->assertRedirect(route('products.index'));

        // O erro reabre o diálogo de envio na aba de onde partiu.
        $this->followingRedirects()->from('/estoque')->post('/estoque/nota-fiscal', ['_dialog' => 'invoice-import', 'invoice' => $this->realUpload('nota.xml', '<a/>', 'text/xml')])
            ->assertSee('data-dialog-autoshow', false)
            ->assertSee('O arquivo não é o XML de uma nota fiscal eletrônica (NF-e).');
    }

    public function test_xml_with_doctype_or_entities_is_refused_before_being_parsed(): void
    {
        $secret = tempnam(sys_get_temp_dir(), 'segredo');
        file_put_contents($secret, 'CONTEUDO-SIGILOSO');

        $malicious = '<?xml version="1.0"?><!DOCTYPE nfeProc [<!ENTITY xxe SYSTEM "file://'.$secret.'">]>'
            .str_replace(['<?xml version="1.0" encoding="UTF-8"?>', 'Distribuidora Alfa Ltda'], ['', '&xxe;'], $this->sampleInvoice());
        $bomb = '<?xml version="1.0"?><!DOCTYPE lolz [<!ENTITY a "aaaaaaaaaa"><!ENTITY b "&a;&a;&a;&a;&a;&a;&a;&a;">]><nfeProc>&b;</nfeProc>';

        $this->actingAs($this->admin);

        foreach ([$malicious, $bomb, mb_convert_encoding('<?xml version="1.0" encoding="UTF-16"?><!DOCTYPE a [<!ENTITY x "y">]><a>&x;</a>', 'UTF-16')] as $xml) {
            $this->upload($xml)->assertSessionHasErrors('invoice');
        }

        $this->get('/estoque/nota-fiscal')->assertRedirect(route('products.index'));

        unlink($secret);
    }

    public function test_review_screen_escapes_what_came_from_the_invoice(): void
    {
        $this->actingAs($this->admin);
        $this->upload($this->invoiceXml(
            [$this->item('<script>alert(1)</script>', '"><img src=x onerror=alert(2)>', 'UN', '1', '1', [['"><b>lote</b>', '1', '2026-01-01', '2027-01-01']])],
            '4521',
            '<script>alert(3)</script>',
        ))->assertSessionHasNoErrors();

        $this->get($this->reviewUrl())
            ->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertDontSee('<img src=x onerror=alert(2)>', false)
            ->assertDontSee('<b>lote</b>', false)
            ->assertDontSee('<script>alert(3)</script>', false);
    }
}
