<?php

namespace App\Http\Controllers;

use App\Enums\AuditAction;
use App\Models\Patient;
use App\Models\Treatment;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class TreatmentNoteController extends Controller
{
    /**
     * Acrescenta uma evolução ou adendo a um atendimento.
     */
    public function store(Request $request, Patient $patient, Treatment $treatment, AuditLogger $audit): RedirectResponse
    {
        Gate::authorize('addNote', $treatment);

        $validated = $request->validate(
            ['body' => ['required', 'string', 'min:3', 'max:5000']],
            [],
            ['body' => 'evolução'],
        );

        DB::transaction(function () use ($request, $patient, $treatment, $validated, $audit): void {
            $note = $treatment->notes()->create(['body' => $validated['body'], 'user_id' => $request->user()->getKey()]);

            $audit->log(AuditAction::TreatmentNoteAdded, $note, ['patient_id' => $patient->getKey(), 'treatment_id' => $treatment->getKey()]);
        });

        return redirect()->route('patients.treatments.show', [$patient, $treatment])->with('success', 'Evolução registrada.');
    }
}
