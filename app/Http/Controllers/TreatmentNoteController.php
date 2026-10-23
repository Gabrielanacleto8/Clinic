<?php

namespace App\Http\Controllers;

use App\Enums\AuditAction;
use App\Http\Requests\TreatmentNoteRequest;
use App\Models\Patient;
use App\Models\Treatment;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

class TreatmentNoteController extends Controller
{
    /**
     * Acrescenta uma evolução ou adendo a um atendimento.
     */
    public function store(TreatmentNoteRequest $request, Patient $patient, Treatment $treatment, AuditLogger $audit): RedirectResponse
    {
        $validated = $request->validated();

        DB::transaction(function () use ($request, $patient, $treatment, $validated, $audit): void {
            $note = $treatment->notes()->create(['body' => $validated['body'], 'user_id' => $request->user()->getKey()]);

            $audit->log(AuditAction::TreatmentNoteAdded, $note, ['patient_id' => $patient->getKey(), 'treatment_id' => $treatment->getKey()]);
        });

        return redirect()->route('patients.treatments.show', [$patient, $treatment])->with('success', 'Evolução registrada.');
    }
}
