<?php

namespace App\Http\Controllers;

use App\Exceptions\UnusableStockException;
use App\Http\Requests\DiscardStockUnitRequest;
use App\Models\ProductBatch;
use App\Models\StockMovement;
use App\Models\StockUnit;
use App\Services\StockLedger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Unidades físicas de produto fracionado: abertura pelo estoque, consulta da
 * rastreabilidade e descarte da sobra. O consumo nasce só do atendimento.
 */
class StockUnitController extends Controller
{
    public function store(Request $request, ProductBatch $batch, StockLedger $ledger): RedirectResponse
    {
        Gate::authorize('move', $batch->product);

        if (! $batch->product->isFractioned()) {
            abort(404);
        }

        try {
            $unit = $ledger->openUnit($batch, $request->user());
        } catch (UnusableStockException $exception) {
            throw ValidationException::withMessages(['unit' => $exception->getMessage()]);
        }

        return redirect()->route('stock.units.show', $unit)->with('success', $unit->label().' aberta. Identifique o frasco com este número.');
    }

    /**
     * Rastreabilidade da unidade: quando foi aberta, onde foi usada e o que foi descartado.
     */
    public function show(StockUnit $unit): View
    {
        $unit->load(['batch.product', 'openedBy:id,name']);

        Gate::authorize('view', $unit->batch->product);

        return view('stock.unit', [
            'unit' => $unit,
            'batch' => $unit->batch,
            'product' => $unit->batch->product,
            'totals' => StockMovement::query()->where('stock_unit_id', $unit->id)->groupBy('type')->selectRaw('type, sum(quantity) as total, sum(quantity * unit_cost) as value')->get()->keyBy(fn ($row) => $row->type->value),
            'movements' => StockMovement::query()
                ->where('stock_unit_id', $unit->id)
                ->with(['user:id,name', 'treatment:id,patient_id,professional_id,procedure_id', 'treatment.professional:id,name', 'treatment.procedure:id,name'])
                ->latest('id')
                ->paginate(20),
        ]);
    }

    public function discard(DiscardStockUnitRequest $request, StockUnit $unit, StockLedger $ledger): RedirectResponse
    {
        $movement = $ledger->discard($unit, (string) $request->validated('reason'), $request->user());

        return redirect()->route('stock.units.show', $unit)
            ->with('success', $movement === null ? 'A unidade já estava vazia. Nada foi alterado.' : 'Descarte registrado.');
    }
}
