<?php

namespace App\Http\Controllers;

use App\Enums\StockMovementType;
use App\Http\Requests\StockEntryRequest;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\StockMovement;
use App\Services\StockAlerts;
use App\Services\StockLedger;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class StockEntryController extends Controller
{
    /**
     * Aba Entradas: o que chegou, com os dados da compra.
     */
    public function index(Request $request, StockAlerts $alerts): View
    {
        Gate::authorize('viewAny', Product::class);

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'de' => ['nullable', 'date_format:Y-m-d'],
            'ate' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $entries = StockMovement::query()
            ->where('type', StockMovementType::Entry)
            ->with(['batch:id,product_id,batch_code,manufactured_on,expires_on', 'batch.product:id,name,unit', 'user:id,name'])
            ->when($filters['q'] ?? null, fn ($query, string $term) => $query->search($term))
            ->inPeriod($filters['de'] ?? null, $filters['ate'] ?? null)
            ->latest('id')
            ->paginate(15)
            ->withQueryString();

        return view('stock.entries', [
            'entries' => $entries,
            'filters' => ['q' => $filters['q'] ?? '', 'de' => $filters['de'] ?? '', 'ate' => $filters['ate'] ?? ''],
            'alerts' => $alerts->summary(),
            // A lista de produtos só serve ao formulário de nova entrada.
            'products' => $request->user()->can('move', Product::class) ? $this->selectableProducts() : new Collection,
        ]);
    }

    public function store(StockEntryRequest $request, StockLedger $ledger): RedirectResponse
    {
        DB::transaction(function () use ($request, $ledger): void {
            $ledger->enter(
                $this->batchForEntry($request),
                (string) $request->validated('quantity'),
                $request->validated('reason'),
                $request->user(),
                $request->safe()->only(['unit_cost', 'supplier', 'invoice_number']),
            );
        });

        return redirect()->back(fallback: route('stock.entries.index'))->with('success', 'Entrada registrada.');
    }

    /**
     * Lote informado na entrada: o existente com aquele código, ou um novo.
     * Fabricação e validade de um lote existente não são trocadas por uma entrada.
     */
    private function batchForEntry(StockEntryRequest $request): ProductBatch
    {
        $code = trim((string) $request->validated('batch_code'));
        $dates = $request->safe()->only(['manufactured_on', 'expires_on']);

        $batch = ProductBatch::query()
            ->where('product_id', $request->validated('product_id'))
            ->where('batch_code', $code)
            ->lockForUpdate()
            ->first();

        if ($batch === null) {
            return ProductBatch::create(['product_id' => $request->validated('product_id'), 'batch_code' => $code, ...$dates]);
        }

        foreach (['manufactured_on' => 'fabricação', 'expires_on' => 'validade'] as $field => $label) {
            $informed = $dates[$field] ?? null;

            if ($informed !== null && $batch->{$field}?->format('Y-m-d') !== $informed) {
                throw ValidationException::withMessages([
                    $field => 'O lote '.$code.' já existe com '.($batch->{$field} ? $label.' '.$batch->{$field}->format('d/m/Y') : $label.' não informada').'.',
                ]);
            }
        }

        return $batch;
    }

    /**
     * @return Collection<int, Product>
     */
    private function selectableProducts(): Collection
    {
        return Product::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'code', 'unit']);
    }
}
