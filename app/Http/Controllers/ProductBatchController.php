<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\StockMovement;
use App\Services\StockAlerts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ProductBatchController extends Controller
{
    private const FILTERS = [
        'com-saldo' => 'Com saldo',
        'vencendo' => 'Vencendo',
        'vencidos' => 'Vencidos com saldo',
        'esgotados' => 'Esgotados',
        'todos' => 'Todos',
    ];

    /**
     * Aba Lotes e validade: saldo por lote, do que vence primeiro ao que vence por último.
     */
    public function index(Request $request, StockAlerts $alerts): View
    {
        Gate::authorize('viewAny', Product::class);

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'situacao' => ['nullable', Rule::in(array_keys(self::FILTERS))],
        ]);

        $status = $filters['situacao'] ?? 'com-saldo';

        $batches = ProductBatch::query()
            ->with('product:id,name,code,unit,is_active')
            ->when($filters['q'] ?? null, fn ($query, string $term) => $query->search($term))
            ->when($status === 'com-saldo', fn ($query) => $query->where('quantity', '>', 0))
            ->when($status === 'vencendo', fn ($query) => $query->expiringSoon())
            ->when($status === 'vencidos', fn ($query) => $query->expiredWithStock())
            ->when($status === 'esgotados', fn ($query) => $query->where('quantity', 0))
            ->firstToExpire()
            ->paginate(15)
            ->withQueryString();

        return view('stock.batches', [
            'batches' => $batches,
            'statusOptions' => self::FILTERS,
            'filters' => ['q' => $filters['q'] ?? '', 'situacao' => $status],
            'alerts' => $alerts->summary(),
        ]);
    }

    /**
     * Rastreabilidade de um lote: de onde veio e em quais atendimentos foi usado.
     */
    public function show(ProductBatch $batch): View
    {
        Gate::authorize('view', $batch->product);

        return view('stock.batch', [
            'batch' => $batch,
            'product' => $batch->product,
            // Soma por tipo de movimentação, para o resumo do lote.
            'totals' => StockMovement::query()->where('product_batch_id', $batch->id)->groupBy('type')->selectRaw('type, sum(quantity) as total')->pluck('total', 'type'),
            'movements' => StockMovement::query()
                ->where('product_batch_id', $batch->id)
                ->with(['user:id,name', 'treatment:id,patient_id,professional_id', 'treatment.professional:id,name'])
                ->latest('id')
                ->paginate(20),
        ]);
    }
}
