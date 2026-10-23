<?php

namespace App\Http\Controllers;

use App\Enums\AuditAction;
use App\Enums\StockMovementType;
use App\Http\Requests\UpdateProductBatchRequest;
use App\Http\Requests\WriteOffExpiredBatchRequest;
use App\Models\AuditLog;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\StockMovement;
use App\Services\AuditLogger;
use App\Services\StockAlerts;
use App\Services\StockLedger;
use App\Support\ClinicTime;
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
            // "Vence até": lotes com saldo que vencem até esta data, qualquer que seja a situação.
            'vence_ate' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $status = $filters['situacao'] ?? 'com-saldo';

        $batches = ProductBatch::query()
            ->with('product:id,name,code,unit,is_active')
            ->when($filters['q'] ?? null, fn ($query, string $term) => $query->search($term))
            ->when($filters['vence_ate'] ?? null, fn ($query, string $until) => $query->where('quantity', '>', 0)->whereBetween('expires_on', [ClinicTime::today(), $until]))
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
            'filters' => ['q' => $filters['q'] ?? '', 'situacao' => $status, 'vence_ate' => $filters['vence_ate'] ?? ''],
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
            // Última conferência de inventário em que este lote foi contado.
            'lastCount' => AuditLog::query()
                ->with('user:id,name')
                ->where('action', AuditAction::StockCounted->value)
                ->whereJsonContains('metadata->batch_ids', $batch->id)
                ->latest('id')
                ->first(),
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
     * Corrige código, fabricação e validade digitados errado. Os valores
     * antigos e os novos entram na auditoria: são dados do lote, não do
     * paciente, e a correção mexe na rastreabilidade.
     */
    public function update(UpdateProductBatchRequest $request, ProductBatch $batch, AuditLogger $audit): RedirectResponse
    {
        $snapshot = fn (ProductBatch $batch) => [
            'batch_code' => $batch->batch_code,
            'manufactured_on' => $batch->manufactured_on?->format('Y-m-d'),
            'expires_on' => $batch->expires_on?->format('Y-m-d'),
        ];

        $changed = DB::transaction(function () use ($request, $batch, $audit, $snapshot): array {
            $batch = ProductBatch::query()->whereKey($batch->getKey())->lockForUpdate()->firstOrFail();
            $before = $snapshot($batch);

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
                'to' => array_intersect_key($snapshot($batch), array_flip($changed)),
            ]);

            return $changed;
        });

        return redirect()->route('stock.batches.show', $batch)
            ->with('success', $changed === [] ? 'Os dados já estavam assim. Nada foi alterado.' : 'Dados do lote corrigidos.');
    }

    /**
     * Baixa um lote vencido inteiro como perda: o fechado e as unidades abertas.
     */
    public function writeOff(WriteOffExpiredBatchRequest $request, ProductBatch $batch, StockLedger $ledger): RedirectResponse
    {
        abort_unless($batch->isExpired(), 404);

        $movements = $ledger->writeOffExpired($batch, (string) $request->validated('reason'), $request->user());

        return redirect()->back(fallback: route('stock.batches.show', $batch))
            ->with('success', $movements === [] ? 'O lote já estava zerado. Nada foi alterado.' : 'Lote vencido baixado como perda ('.count($movements).' '.(count($movements) === 1 ? 'movimentação' : 'movimentações').').');
    }
}
