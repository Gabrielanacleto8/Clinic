<?php

namespace App\Http\Controllers;

use App\Enums\AuditAction;
use App\Http\Requests\AnamnesisRequest;
use App\Models\Patient;
use App\Models\PatientAnamnesis;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class AnamnesisController extends Controller
{
    private bool $reviewed = false;

    public function __construct(private readonly AuditLogger $audit) {}

    public function edit(Patient $patient): View
    {
        Gate::authorize('updateClinicalRecords', $patient);

        $this->audit->log(AuditAction::PatientViewed, $patient, ['context' => 'anamnesis']);

        return view('anamnesis.edit', [
            'patient' => $patient,
            'anamnesis' => $patient->anamnesis ?? new PatientAnamnesis,
        ]);
    }

    public function update(AnamnesisRequest $request, Patient $patient): RedirectResponse
    {
        DB::transaction(function () use ($request, $patient): void {
            $anamnesis = $patient->anamnesis ?? $patient->anamnesis()->make();
            $anamnesis->fill($request->validated());

            $changed = array_keys($anamnesis->getDirty());
            $anamnesis->updated_by = $request->user()->getKey();

            // Salvar sem mudar nada é a revisão com o paciente: fica registrado quem conferiu e quando.
            if ($changed === [] && $anamnesis->exists) {
                $anamnesis->touch();
                $this->audit->log(AuditAction::AnamnesisReviewed, $patient);
                $this->reviewed = true;

                return;
            }

            $anamnesis->save();

            // Só os nomes dos campos entram na auditoria, nunca os valores.
            $this->audit->log(AuditAction::AnamnesisUpdated, $patient, ['fields' => array_values(array_diff($changed, ['patient_id']))]);
        });

        return redirect()->route('patients.show', $patient)
            ->with('success', $this->reviewed ? 'Ficha de saúde revisada: nada mudou.' : 'Ficha de saúde atualizada.');
    }

    /**
     * Avisos de saúde do paciente escolhido no diálogo "Registrar atendimento":
     * os mesmos que o formulário do prontuário mostra antes de um procedimento.
     */
    public function alerts(Patient $patient): JsonResponse
    {
        Gate::authorize('viewClinicalRecords', $patient);

        $this->audit->log(AuditAction::PatientViewed, $patient, ['context' => 'health_alerts']);

        $anamnesis = $patient->anamnesis;

        return response()->json([
            // Sem ficha, o diálogo avisa; com ficha antiga, pede revisão.
            'missing' => $anamnesis === null,
            'stale_since' => $anamnesis?->isStale() ? $anamnesis->updated_at->format('d/m/Y') : null,
            'alerts' => $anamnesis?->alerts() ?? [],
        ]);
    }
}
