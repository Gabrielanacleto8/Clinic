<?php

namespace App\Http\Controllers;

use App\Enums\AuditAction;
use App\Models\ClinicProfile;
use App\Models\Patient;
use App\Models\Procedure;
use App\Services\AuditLogger;
use App\Support\ConsentTerm;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Termo de consentimento para imprimir e assinar antes do procedimento. Leva
 * nome e CPF do paciente: é documento do prontuário, com o mesmo acesso.
 */
class ConsentTermController extends Controller
{
    public function show(Request $request, Patient $patient, AuditLogger $audit): View
    {
        Gate::authorize('viewClinicalRecords', $patient);

        $procedureId = $request->validate([
            'procedimento' => ['nullable', 'integer', Rule::exists(Procedure::class, 'id')],
        ])['procedimento'] ?? null;

        // Sem procedimento escolhido: a lista para escolher. Ainda não há dado do paciente além do nome.
        if ($procedureId === null) {
            return view('patients.consent-term-choose', [
                'patient' => $patient,
                'procedures' => Procedure::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'consent_template']),
            ]);
        }

        $procedure = Procedure::query()->findOrFail((int) $procedureId, ['id', 'name', 'consent_template']);
        $clinic = ClinicProfile::current();

        $audit->log(AuditAction::ConsentTermPrinted, $patient, ['procedure_id' => $procedure->getKey()]);

        return view('patients.consent-term', [
            'patient' => $patient,
            'procedure' => $procedure,
            'clinic' => $clinic,
            'text' => ConsentTerm::render($procedure, $patient, $clinic),
        ]);
    }
}
