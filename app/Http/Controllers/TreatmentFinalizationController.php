<?php

namespace App\Http\Controllers;

use App\Http\Requests\FinalizeTreatmentRequest;
use App\Models\Patient;
use App\Models\ProductBatch;
use App\Models\Treatment;
use App\Services\TreatmentFinalizer;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class TreatmentFinalizationController extends Controller
{
    /**
     * Tela de conferência: o que será baixado do estoque e quanto será cobrado.
     */
    public function create(Patient $patient, Treatment $treatment): View
    {
        Gate::authorize('finalize', $treatment);

        $treatment->load(['procedure:id,name,price', 'professional:id,name', 'products.batch.product:id,name,unit']);

        return view('treatments.finalize', [
            'patient' => $patient,
            'treatment' => $treatment,
            'firstToExpire' => $this->firstBatchesToExpire($treatment),
        ]);
    }

    /**
     * Para cada produto do atendimento, o lote que deveria sair primeiro: o de
     * vencimento mais próximo. A tela avisa quando o escolhido é outro.
     *
     * @return Collection<int, ProductBatch> Indexada pelo id do produto.
     */
    private function firstBatchesToExpire(Treatment $treatment): Collection
    {
        $productIds = $treatment->products->pluck('batch.product_id')->unique();

        if ($productIds->isEmpty()) {
            return new Collection;
        }

        return ProductBatch::query()
            ->whereIn('product_id', $productIds)
            ->availableForUse()
            ->firstToExpire()
            ->get(['id', 'product_id', 'batch_code', 'expires_on'])
            ->unique('product_id')
            ->keyBy('product_id');
    }

    public function store(FinalizeTreatmentRequest $request, Patient $patient, Treatment $treatment, TreatmentFinalizer $finalizer): RedirectResponse
    {
        $finalizer->finalize($treatment, $request->user(), $request->payment());

        return redirect()->route('patients.treatments.show', [$patient, $treatment])
            ->with('success', 'Atendimento finalizado. Estoque e financeiro foram atualizados.');
    }
}
