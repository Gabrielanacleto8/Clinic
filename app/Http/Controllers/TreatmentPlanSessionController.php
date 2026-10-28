<?php

namespace App\Http\Controllers;

use App\Models\Patient;
use App\Models\Treatment;
use App\Models\TreatmentPlanItem;
use App\Services\TreatmentFormOptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Sessões de pacote que o paciente ainda pode usar, para o diálogo
 * "Registrar atendimento", que só conhece o paciente depois da escolha.
 */
class TreatmentPlanSessionController extends Controller
{
    public function index(Patient $patient, TreatmentFormOptions $options): JsonResponse
    {
        Gate::authorize('create', Treatment::class);
        Gate::authorize('viewClinicalRecords', $patient);

        $items = $options->planSessions($patient);

        return response()->json([
            'sessions' => $items->map(fn (TreatmentPlanItem $item) => [
                'id' => $item->id,
                'procedure_id' => $item->procedure_id,
                'label' => $item->sessionLabel(),
            ])->values(),
        ]);
    }
}
