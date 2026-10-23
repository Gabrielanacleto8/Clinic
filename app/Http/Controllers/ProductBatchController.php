<?php

namespace App\Http\Controllers;

use App\Enums\AuditAction;
use App\Enums\StockMovementType;
use App\Http\Requests\UpdateProductBatchRequest;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\StockMovement;
use App\Services\AuditLogger;
use App\Services\StockAlerts;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ProductBatchController extends Controller
{
    private const FILTERS = [
        'com-saldo' => 'Com saldo',
        'vencendo' => 'Vencendo',
        'vencidos' => 'Vencidos com saldo',
        'descartar' => 'Com unidade aberta a descartar',
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
            ->when($status === 'descartar', fn ($query) => $query->whereHas('units', fn ($units) => $units->awaitingDiscard()))
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

        $totals = StockMovement::query()->where('product_batch_id', $batch->id)->groupBy('type')
            ->selectRaw('type, sum(quantity) as total, sum(quantity * unit_cost) as value')->get()->keyBy(fn ($row) => $row->type->value);

        // Produto fracionado: o que resta em cada unidade aberta.
        $units = $batch->product->isFractioned()
            ? $batch->units()->withBalance()->withConsumption()->orderBy('opened_at')->orderBy('id')->get()->each(fn ($unit) => $unit->setRelation('batch', $batch))
            : collect();

        $batch->setAttribute('open_quantity', $units->sum('quantity'));

        return view('stock.batch', [
            'batch' => $batch,
            'product' => $batch->product,
            // Soma por tipo de movimentação, para o resumo do lote.
            'totals' => $totals->map(fn ($row) => $row->total),
            'lostValue' => abs((float) ($totals->get(StockMovementType::Loss->value)?->value ?? 0)),
            'units' => $units,
            'movements' => StockMovement::query()
                ->where('product_batch_id', $batch->id)
                ->with(['user:id,name', 'treatment:id,patient_id,professional_id', 'treatment.professional:id,name'])
                ->latest('id')
                ->paginate(20),
        ]);
    }

    /**
     * Corrige fabricação e validade digitadas errado. As datas antigas e as
     * novas entram na auditoria: são dados do lote, não do paciente, e a
     * correção mexe na rastreabilidade.
     */
    public function update(UpdateProductBatchRequest $request, ProductBatch $batch, AuditLogger $audit): RedirectResponse
    {
        $changed = DB::transaction(function () use ($request, $batch, $audit): array {
            $batch = ProductBatch::query()->whereKey($batch->getKey())->lockForUpdate()->firstOrFail();
            $before = ['manufactured_on' => $batch->manufactured_on?->format('Y-m-d'), 'expires_on' => $batch->expires_on?->format('Y-m-d')];

            $batch->fill($request->validated());
            $changed = array_keys($batch->getDirty());

            if ($changed === []) {
                return [];
            }

            $batch->save();

            $audit->log(AuditAction::ProductBatchUpdated, $batch, [
                'product_id' => $batch->product_id,
                'fields' => $changed,
                'from' => array_intersect_key($before, array_flip($changed)),
                'to' => array_intersect_key(['manufactured_on' => $batch->manufactured_on?->format('Y-m-d'), 'expires_on' => $batch->expires_on?->format('Y-m-d')], array_flip($changed)),
            ]);

            return $changed;
        });

        return redirect()->route('stock.batches.show', $batch)
            ->with('success', $changed === [] ? 'As datas já estavam assim. Nada foi alterado.' : 'Datas do lote corrigidas.');
    }
}
