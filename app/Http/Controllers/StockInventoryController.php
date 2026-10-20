<?php

namespace App\Http\Controllers;

use App\Enums\ProductCategory;
use App\Exceptions\InsufficientStockException;
use App\Http\Requests\StockInventoryRequest;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Services\StockLedger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Inventário: conferência do saldo físico de vários lotes de uma vez. Cada
 * diferença vira uma movimentação de ajuste no livro de estoque.
 */
class StockInventoryController extends Controller
{
    public function edit(Request $request): View
    {
        Gate::authorize('adjust', Product::class);

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'categoria' => ['nullable', Rule::enum(ProductCategory::class)],
        ]);

        // Lotes com saldo no sistema, agrupados por produto na ordem de uso.
        $batches = ProductBatch::query()
            ->select('product_batches.*')
            ->join('products', 'products.id', '=', 'product_batches.product_id')
            ->with('product:id,name,code,unit')
            ->where('product_batches.quantity', '>', 0)
            ->when($filters['q'] ?? null, fn ($query, string $term) => $query->search($term))
            ->when($filters['categoria'] ?? null, fn ($query, string $category) => $query->where('products.category', $category))
            ->orderBy('products.name')
            ->orderByRaw('product_batches.expires_on is null')
            ->orderBy('product_batches.expires_on')
            ->orderBy('product_batches.id')
            ->paginate(StockInventoryRequest::PER_PAGE)
            ->withQueryString();

        return view('stock.inventory', [
            'batches' => $batches,
            'filters' => ['q' => $filters['q'] ?? '', 'categoria' => $filters['categoria'] ?? ''],
        ]);
    }

    public function update(StockInventoryRequest $request, StockLedger $ledger): RedirectResponse
    {
        $counts = $request->counts();

        if ($counts === []) {
            throw ValidationException::withMessages(['counted' => 'Informe a quantidade contada de pelo menos um lote.']);
        }

        $adjusted = DB::transaction(function () use ($request, $ledger, $counts): int {
            $batches = ProductBatch::query()->whereKey(array_keys($counts))->get();

            if ($batches->count() !== count($counts)) {
                throw ValidationException::withMessages(['counted' => 'Um dos lotes informados não existe mais. Recarregue a página.']);
            }

            return $batches->filter(function (ProductBatch $batch) use ($ledger, $counts, $request): bool {
                try {
                    return $ledger->adjustTo($batch, $counts[$batch->id], (string) $request->validated('reason'), $request->user()) !== null;
                } catch (InsufficientStockException) {
                    // Produto fracionado: a contagem não pode ficar abaixo do que resta nas unidades abertas.
                    throw ValidationException::withMessages([
                        "counted.{$batch->id}" => 'A contagem é menor que o que resta nas unidades abertas deste lote. Descarte essas unidades antes.',
                    ]);
                }
            })->count();
        });

        $matched = count($counts) - $adjusted;

        return redirect()->back(fallback: route('stock.inventory.edit'))->with('success', match (true) {
            $adjusted === 0 => 'Inventário conferido: todas as contagens batem com o saldo.',
            default => "Inventário registrado: {$adjusted} ".($adjusted === 1 ? 'lote ajustado' : 'lotes ajustados')
                .($matched > 0 ? " e {$matched} ".($matched === 1 ? 'confere' : 'conferem').' com o saldo.' : '.'),
        });
    }
}
