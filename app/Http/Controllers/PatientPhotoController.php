<?php

namespace App\Http\Controllers;

use App\Enums\AuditAction;
use App\Http\Requests\StorePatientPhotosRequest;
use App\Models\Patient;
use App\Models\PatientAttachment;
use App\Services\AttachmentVault;
use App\Services\AuditLogger;
use App\Services\PhotoUploader;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Fotos do paciente. O envio por aqui cria fotos avulsas, com data própria,
 * para o acervo anterior ao sistema. Ler e remover vale para qualquer foto do
 * paciente, com ou sem atendimento: é a única porta de saída de uma foto.
 */
class PatientPhotoController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly AttachmentVault $vault,
    ) {}

    public function store(StorePatientPhotosRequest $request, Patient $patient, PhotoUploader $uploader): RedirectResponse
    {
        $uploader->upload($request->file('photos'), $request->safe()->only(['stage', 'caption', 'taken_on']), $patient, null, $request->user());

        $count = count($request->file('photos'));

        return redirect()->route('patients.show', [$patient, 'aba' => 'fotos'])
            ->with('success', $count === 1 ? 'Foto adicionada.' : "{$count} fotos adicionadas.");
    }

    public function show(Request $request, Patient $patient, PatientAttachment $photo): Response
    {
        Gate::authorize('viewClinicalRecords', $patient);

        $thumbnail = $request->boolean('miniatura');

        // A miniatura aparece na ficha do paciente ou na página do atendimento,
        // cujo acesso já é auditado. Abrir a foto em tamanho real gera registro próprio.
        if (! $thumbnail) {
            $this->audit->log(AuditAction::PhotoViewed, $photo, [
                'patient_id' => $patient->getKey(),
                'treatment_id' => $photo->treatment_id,
            ]);
        }

        $contents = $this->vault->read($thumbnail ? $photo->thumbnail_path : $photo->path);

        return response($contents, 200, [
            'Content-Type' => 'image/jpeg',
            'Content-Length' => (string) strlen($contents),
            'Content-Disposition' => 'inline; filename="foto-'.$photo->getKey().'.jpg"',
        ]);
    }

    public function destroy(Patient $patient, PatientAttachment $photo): RedirectResponse
    {
        Gate::authorize('detachPhotos', $patient);

        DB::transaction(function () use ($patient, $photo): void {
            // Remoção lógica: a foto some das telas, mas o arquivo é mantido.
            $photo->delete();

            $this->audit->log(AuditAction::PhotoDeleted, $photo, [
                'patient_id' => $patient->getKey(),
                'treatment_id' => $photo->treatment_id,
            ]);
        });

        // Volta para onde o usuário estava: a página do atendimento ou a ficha.
        return redirect()->back(fallback: route('patients.show', [$patient, 'aba' => 'fotos']))->with('success', 'Foto removida.');
    }
}
