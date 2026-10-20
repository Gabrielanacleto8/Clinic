<?php

namespace App\Http\Controllers;

use App\Enums\StockMovementType;
use App\Exceptions\InsufficientStockException;
use App\Http\Requests\StockMovementRequest;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\StockMovement;
use App\Services\StockAlerts;
use App\Services\StockLedger;
use App\Support\LocalizedDecimal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
            // Valor do que foi perdido ou descartado dentro dos mesmos filtros.
            'lostValue' => abs((float) (clone $filtered)->where('type', StockMovementType::Loss->value)->sum(DB::raw('quantity * unit_cost'))),
            'filters' => ['q' => $filters['q'] ?? '', 'tipo' => $filters['tipo'] ?? '', 'de' => $filters['de'] ?? '', 'ate' => $filters['ate'] ?? ''],
            'alerts' => $alerts->summary(),
        ]);
    }

    public function store(StockMovementRequest $request, Product $product, StockLedger $ledger): RedirectResponse
    {
        $batch = ProductBatch::findOrFail($request->validated('product_batch_id'));
        $quantity = (string) $request->validated('quantity');
        $reason = (string) $request->validated('reason');

        // Produto vencido não circula: só sai do estoque como perda.
        if ($request->type() === StockMovementType::Exit && $batch->isExpired()) {
            throw ValidationException::withMessages(['product_batch_id' => 'Este lote está vencido. Registre a baixa como perda.']);
        }

        try {
            if ($request->type() === StockMovementType::Adjustment) {
                $message = $ledger->adjustTo($batch, $quantity, $reason, $request->user()) === null
                    ? 'A contagem confere com o saldo. Nada foi alterado.'
                    : 'Ajuste registrado.';
            } elseif ($request->type() === StockMovementType::Loss) {
                $ledger->lose($batch, $quantity, $reason, $request->user());
                $message = 'Perda registrada.';
            } else {
                $ledger->withdraw($batch, $quantity, $reason, $request->user());
                $message = 'Saída registrada.';
            }
        } catch (InsufficientStockException $exception) {
            throw ValidationException::withMessages([
                'quantity' => 'Saldo insuficiente no lote '.$exception->batch->batch_code.'. Disponível: '.LocalizedDecimal::quantity($exception->available).'.',
            ]);
        }

        return redirect()->back(fallback: route('products.show', $product))->with('success', $message);
    }
}
