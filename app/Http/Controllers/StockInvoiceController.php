<?php

namespace App\Http\Controllers;

use App\Enums\StockMovementType;
use App\Exceptions\InvalidInvoiceException;
use App\Http\Requests\ImportInvoiceRequest;
use App\Http\Requests\UploadInvoiceRequest;
use App\Models\Product;
use App\Models\StockInvoice;
use App\Models\StockMovement;
use App\Services\InvoiceXmlReader;
use App\Services\PendingInvoiceImports;
use App\Services\StockInvoiceImporter;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Importação de nota fiscal (XML da NF-e): cadastra os produtos que ainda
 * não existem e lança as entradas. O XML é lido e descartado; entre o envio
 * e a confirmação, os itens lidos ficam na sessão (PendingInvoiceImports).
 */
class StockInvoiceController extends Controller
{
    public function __construct(private readonly PendingInvoiceImports $pending) {}

    public function store(UploadInvoiceRequest $request, InvoiceXmlReader $reader): RedirectResponse
    {
        try {
            $invoice = $reader->read($request->file('invoice')->getContent());
        } catch (InvalidInvoiceException $exception) {
            throw ValidationException::withMessages(['invoice' => $exception->getMessage()]);
        }

        return redirect()->route('stock.invoice.edit', ['importacao' => $this->pending->put($request->session(), $invoice)]);
    }

    /**
     * Conferência: nada é gravado antes de o usuário revisar e confirmar os itens.
     */
    public function edit(Request $request): View|RedirectResponse
    {
        Gate::authorize('create', Product::class);

        $token = $request->query('importacao');
        $invoice = $this->pending->get($request->session(), $token);

        if ($invoice === null) {
            return redirect()->route('products.index')->with('warning', 'Envie o XML da nota fiscal para começar a importação.');
        }

        $products = Product::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'code', 'unit', 'unit_content']);
        $imported = $this->importedInvoice($invoice);

        return view('stock.invoice', [
            'token' => $token,
            'invoice' => $invoice,
            'products' => $products,
            'matches' => $this->matches($invoice['items'], $products),
            'inactiveMatches' => $this->inactiveMatches($invoice['items']),
            'imported' => $imported,
            // Notas lançadas antes do registro pela chave de acesso: só um
            // alerta pelo número e fornecedor das entradas.
            'alreadyImported' => $imported === null && $invoice['number'] !== '' && StockMovement::query()
                ->where('type', StockMovementType::Entry)
                ->where('invoice_number', $invoice['number'])
                ->where('supplier', $invoice['supplier'])
                ->exists(),
        ]);
    }

    public function update(ImportInvoiceRequest $request, StockInvoiceImporter $importer): RedirectResponse
    {
        $token = (string) $request->validated('importacao');
        $invoice = $this->pending->get($request->session(), $token);

        if ($invoice === null) {
            return redirect()->route('products.index')->with('warning', 'A importação expirou ou já foi concluída. Envie o XML da nota fiscal de novo.');
        }

        $result = $importer->import($invoice, $request->rows(), $request->user());

        $this->pending->forget($request->session(), $token);

        $plural = fn (int $total, string $one, string $many) => $total.' '.($total === 1 ? $one : $many);

        return redirect()
            ->route($result['entries'] > 0 ? 'stock.entries.index' : 'products.index')
            ->with('success', 'Nota fiscal importada: '.$plural($result['products'], 'produto cadastrado', 'produtos cadastrados')
                .' e '.$plural($result['entries'], 'entrada lançada', 'entradas lançadas').'.');
    }

    public function destroy(Request $request): RedirectResponse
    {
        Gate::authorize('create', Product::class);

        $this->pending->forget($request->session(), $request->input('importacao'));

        return redirect()->route('products.index')->with('success', 'Importação cancelada. Nada foi gravado.');
    }

    /**
     * @param  array<string, mixed>  $invoice
     */
    private function importedInvoice(array $invoice): ?StockInvoice
    {
        if (($invoice['access_key'] ?? null) === null) {
            return null;
        }

        return StockInvoice::query()->with('user:id,name')->where('access_key', $invoice['access_key'])->first();
    }

    /**
     * Produto já cadastrado que corresponde a cada item da nota, pelo código
     * ou, na falta dele, pelo nome. A lista já carregada basta: sem nova consulta.
     *
     * @param  list<array<string, mixed>>  $items
     * @param  Collection<int, Product>  $products
     * @return array<int, int> Índice do item => id do produto.
     */
    private function matches(array $items, Collection $products): array
    {
        $byCode = $products->whereNotNull('code')->keyBy(fn (Product $product) => mb_strtolower($product->code));
        $byName = $products->keyBy(fn (Product $product) => mb_strtolower($product->name));

        return collect($items)
            ->map(fn (array $item) => ($byCode[mb_strtolower($item['code'])] ?? $byName[mb_strtolower($item['name'])] ?? null)?->id)
            ->filter()
            ->all();
    }

    /**
     * Produto inativo com o mesmo nome ou código de cada item. Ele não aparece
     * na lista de produtos, mas impede cadastrar o item como produto novo.
     *
     * @param  list<array<string, mixed>>  $items
     * @return array<int, string> Índice do item => nome do produto inativo.
     */
    private function inactiveMatches(array $items): array
    {
        $names = array_map(fn (array $item) => $item['name'], $items);
        $codes = array_filter(array_map(fn (array $item) => $item['code'], $items), filled(...));

        $inactive = Product::query()
            ->where('is_active', false)
            ->where(fn ($query) => $query->whereIn('name', $names)->orWhereIn('code', $codes))
            ->get(['id', 'name', 'code']);

        if ($inactive->isEmpty()) {
            return [];
        }

        return collect($items)
            ->map(fn (array $item) => $inactive->first(fn (Product $product) => mb_strtolower($product->name) === mb_strtolower($item['name'])
                || ($product->code !== null && $item['code'] !== '' && mb_strtolower($product->code) === mb_strtolower($item['code'])))?->name)
            ->filter()
            ->all();
    }
}
