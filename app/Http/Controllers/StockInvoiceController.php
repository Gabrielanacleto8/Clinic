<?php

namespace App\Http\Controllers;

use App\Enums\StockMovementType;
use App\Exceptions\InvalidInvoiceException;
use App\Http\Requests\ImportInvoiceRequest;
use App\Http\Requests\UploadInvoiceRequest;
use App\Models\Product;
use App\Models\StockMovement;
use App\Services\InvoiceXmlReader;
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
 * e a confirmação, os itens lidos ficam na sessão do usuário.
 */
class StockInvoiceController extends Controller
{
    private const SESSION_KEY = 'stock.invoice_import';

    public function store(UploadInvoiceRequest $request, InvoiceXmlReader $reader): RedirectResponse
    {
        try {
            $invoice = $reader->read($request->file('invoice')->getContent());
        } catch (InvalidInvoiceException $exception) {
            throw ValidationException::withMessages(['invoice' => $exception->getMessage()]);
        }

        $request->session()->put(self::SESSION_KEY, $invoice);

        return redirect()->route('stock.invoice.edit');
    }

    /**
     * Conferência: nada é gravado antes de o usuário revisar e confirmar os itens.
     */
    public function edit(Request $request): View|RedirectResponse
    {
        Gate::authorize('create', Product::class);

        $invoice = $request->session()->get(self::SESSION_KEY);

        if ($invoice === null) {
            return redirect()->route('products.index')->with('warning', 'Envie o XML da nota fiscal para começar a importação.');
        }

        $products = Product::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'code', 'unit']);

        return view('stock.invoice', [
            'invoice' => $invoice,
            'products' => $products,
            'matches' => $this->matches($invoice['items'], $products),
            // Mesma nota do mesmo fornecedor já lançada: alerta contra entrada em dobro.
            'alreadyImported' => $invoice['number'] !== '' && StockMovement::query()
                ->where('type', StockMovementType::Entry)
                ->where('invoice_number', $invoice['number'])
                ->where('supplier', $invoice['supplier'])
                ->exists(),
        ]);
    }

    public function update(ImportInvoiceRequest $request, StockInvoiceImporter $importer): RedirectResponse
    {
        $invoice = $request->session()->get(self::SESSION_KEY);

        if ($invoice === null) {
            return redirect()->route('products.index')->with('warning', 'A importação expirou. Envie o XML da nota fiscal de novo.');
        }

        $result = $importer->import($invoice, $request->rows(), $request->user());

        $request->session()->forget(self::SESSION_KEY);

        $plural = fn (int $total, string $one, string $many) => $total.' '.($total === 1 ? $one : $many);

        return redirect()
            ->route($result['entries'] > 0 ? 'stock.entries.index' : 'products.index')
            ->with('success', 'Nota fiscal importada: '.$plural($result['products'], 'produto cadastrado', 'produtos cadastrados')
                .' e '.$plural($result['entries'], 'entrada lançada', 'entradas lançadas').'.');
    }

    public function destroy(Request $request): RedirectResponse
    {
        Gate::authorize('create', Product::class);

        $request->session()->forget(self::SESSION_KEY);

        return redirect()->route('products.index')->with('success', 'Importação cancelada. Nada foi gravado.');
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
}
