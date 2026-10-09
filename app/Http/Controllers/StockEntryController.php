<?php

namespace App\Http\Controllers;

use App\Enums\StockMovementType;
use App\Http\Requests\StockEntryRequest;
use App\Models\Product;
use App\Models\StockMovement;
use App\Services\StockAlerts;
use App\Services\StockReceiver;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
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

    public function store(StockEntryRequest $request, StockReceiver $receiver): RedirectResponse
    {
        $receiver->receive((int) $request->validated('product_id'), $request->safe()->except('product_id'), $request->user());

        return redirect()->back(fallback: route('stock.entries.index'))->with('success', 'Entrada registrada.');
    }

    /**
     * @return Collection<int, Product>
     */
    private function selectableProducts(): Collection
    {
        return Product::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'code', 'unit']);
    }
}
