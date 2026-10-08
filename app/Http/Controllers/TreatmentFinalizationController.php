<?php

namespace App\Http\Controllers;

use App\Http\Requests\FinalizeTreatmentRequest;
use App\Models\Patient;
use App\Models\Treatment;
use App\Services\TreatmentFinalizer;
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

        return view('treatments.finalize', [
            'patient' => $patient,
            'treatment' => $treatment->load(['procedure:id,name,price', 'professional:id,name', 'products.batch.product:id,name,unit']),
        ]);
    }

    public function store(FinalizeTreatmentRequest $request, Patient $patient, Treatment $treatment, TreatmentFinalizer $finalizer): RedirectResponse
    {
        $finalizer->finalize($treatment, $request->user(), $request->payment());

        return redirect()->route('patients.treatments.show', [$patient, $treatment])
            ->with('success', 'Atendimento finalizado. Estoque e financeiro foram atualizados.');
    }
}
