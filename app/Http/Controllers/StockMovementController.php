<?php

namespace App\Http\Controllers;

use App\Enums\StockMovementType;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\StaleStockException;
use App\Exceptions\UnusableStockException;
use App\Http\Requests\StockMovementRequest;
use App\Http\Requests\UpdateEntryCostRequest;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\StockMovement;
use App\Services\StockAlerts;
use App\Services\StockLedger;
use App\Support\LocalizedDecimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class StockMovementController extends Controller
{
    /**
     * Aba Movimentações: o livro de estoque inteiro, do mais recente ao mais antigo.
     */
    public function index(Request $request, StockAlerts $alerts): View
    {
        Gate::authorize('viewAny', Product::class);

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'tipo' => ['nullable', Rule::enum(StockMovementType::class)],
            'de' => ['nullable', 'date_format:Y-m-d'],
            'ate' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $filtered = StockMovement::query()
            ->when($filters['q'] ?? null, fn ($query, string $term) => $query->search($term))
            ->when($filters['tipo'] ?? null, fn ($query, string $type) => $query->where('type', $type))
            ->inPeriod($filters['de'] ?? null, $filters['ate'] ?? null);

        $movements = (clone $filtered)
            ->with([
                'batch:id,product_id,batch_code',
                'batch.product:id,name,unit',
                'user:id,name',
                // Consumo: o atendimento e o profissional que aplicou o produto.
                'treatment:id,patient_id,professional_id',
                'treatment.professional:id,name',
            ])
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return view('stock.movements', [
            'movements' => $movements,
            'summary' => $this->summary(clone $filtered),
            'filters' => ['q' => $filters['q'] ?? '', 'tipo' => $filters['tipo'] ?? '', 'de' => $filters['de'] ?? '', 'ate' => $filters['ate'] ?? ''],
            'alerts' => $alerts->summary(),
        ]);
    }

    /**
     * Totais por tipo dentro dos filtros, em uma consulta: lançamentos,
     * quantidade e valor (entrada pelo custo da compra; o resto pelo custo
     * médio do lote na baixa). O consumo sai líquido do que foi estornado
     * por atendimentos anulados, que é o custo real do que foi aplicado.
     *
     * @param  Builder<StockMovement>  $filtered
     * @return array<string, array{count: int, quantity: float, value: float}>
     */
    private function summary(Builder $filtered): array
    {
        $rows = $filtered
            ->groupBy('type')
            ->selectRaw('type, count(*) as total, sum(quantity) as quantity, coalesce(sum(abs(quantity) * unit_cost), 0) as value')
            ->get()
            ->keyBy(fn ($row) => $row->type->value);

        $summary = [];

        foreach (StockMovementType::cases() as $type) {
            $row = $rows->get($type->value);

            if ($row !== null) {
                $summary[$type->value] = ['count' => (int) $row->total, 'quantity' => abs((float) $row->quantity), 'value' => (float) $row->value];
            }
        }

        $reversal = $summary[StockMovementType::Reversal->value] ?? null;

        if ($reversal !== null && isset($summary[StockMovementType::Consumption->value])) {
            $summary[StockMovementType::Consumption->value]['net_quantity'] = max(0, $summary[StockMovementType::Consumption->value]['quantity'] - $reversal['quantity']);
            $summary[StockMovementType::Consumption->value]['net_value'] = max(0, $summary[StockMovementType::Consumption->value]['value'] - $reversal['value']);
        }

        return $summary;
    }

    /**
     * Corrige o custo unitário de uma entrada digitado errado.
     */
    public function updateCost(UpdateEntryCostRequest $request, StockMovement $movement, StockLedger $ledger): RedirectResponse
    {
        $unitCost = $request->validated('unit_cost');
        $changed = $ledger->correctEntryCost($movement, is_numeric($unitCost) ? (string) $unitCost : null, $request->user());

        return redirect()->back(fallback: route('stock.batches.show', $movement->product_batch_id))
            ->with('success', $changed ? 'Custo da entrada corrigido. O custo médio do lote já considera o valor novo.' : 'O custo já era esse. Nada foi alterado.');
    }

    public function store(StockMovementRequest $request, Product $product, StockLedger $ledger): RedirectResponse
    {
        $batch = ProductBatch::findOrFail($request->validated('product_batch_id'));
        $quantity = $request->quantity();
        $reason = (string) $request->validated('reason');

        try {
            if ($request->type() === StockMovementType::Adjustment) {
                $message = $ledger->adjustTo($batch, $quantity, $reason, $request->user(), $request->expected()) === null
                    ? 'A contagem confere com o saldo. Nada foi alterado.'
                    : 'Ajuste registrado.';
            } elseif ($request->type() === StockMovementType::Loss) {
                $ledger->lose($batch, $quantity, $reason, $request->user());
                $message = 'Perda registrada.';
            } else {
                $ledger->withdraw($batch, $quantity, $reason, $request->user());
                $message = 'Saída registrada.';
            }
        } catch (UnusableStockException $exception) {
            // Produto vencido não circula: só sai do estoque como perda.
            throw ValidationException::withMessages(['product_batch_id' => $exception->getMessage()]);
        } catch (StaleStockException $exception) {
            throw ValidationException::withMessages(['quantity' => $exception->getMessage()]);
        } catch (InsufficientStockException $exception) {
            throw ValidationException::withMessages([
                'quantity' => ($batch->product->isFractioned() ? 'Saldo fechado insuficiente no lote ' : 'Saldo insuficiente no lote ')
                    .$exception->batch->batch_code.'. Disponível: '.LocalizedDecimal::quantity($exception->available).' '.$batch->product->unit->symbol().'.',
            ]);
        }

        return redirect()->back(fallback: route('products.show', $product))->with('success', $message);
    }
}
