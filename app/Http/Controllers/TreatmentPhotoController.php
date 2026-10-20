<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePhotosRequest;
use App\Models\Patient;
use App\Models\Treatment;
use App\Services\PhotoUploader;
use Illuminate\Http\RedirectResponse;

/**
 * Envio de fotos de um atendimento. Ver e remover é pela PatientPhotoController,
 * que alcança qualquer foto do paciente.
 */
class TreatmentPhotoController extends Controller
{
    public function store(StorePhotosRequest $request, Patient $patient, Treatment $treatment, PhotoUploader $uploader): RedirectResponse
    {
        $uploader->upload($request->file('photos'), $request->safe()->only(['stage', 'caption']), $patient, $treatment, $request->user());

        $count = count($request->file('photos'));

        return redirect()->route('patients.treatments.show', [$patient, $treatment])
            ->with('success', $count === 1 ? 'Foto adicionada.' : "{$count} fotos adicionadas.");
    }
}
