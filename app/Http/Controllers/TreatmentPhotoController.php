<?php

namespace App\Http\Controllers;

use App\Enums\AttachmentType;
use App\Enums\AuditAction;
use App\Exceptions\InvalidPhotoException;
use App\Http\Requests\StorePhotosRequest;
use App\Models\Patient;
use App\Models\Treatment;
use App\Models\TreatmentAttachment;
use App\Services\AttachmentVault;
use App\Services\AuditLogger;
use App\Services\PhotoProcessor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Throwable;

class TreatmentPhotoController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly AttachmentVault $vault,
    ) {}

    public function store(StorePhotosRequest $request, Patient $patient, Treatment $treatment, PhotoProcessor $processor): RedirectResponse
    {
        $stored = [];

        try {
            DB::transaction(function () use ($request, $patient, $treatment, $processor, &$stored): void {
                foreach ($request->file('photos') as $file) {
                    $processed = $processor->process($file);

                    $stored[] = $path = $this->vault->store($patient->getKey(), $processed['image']);
                    $stored[] = $thumbnailPath = $this->vault->store($patient->getKey(), $processed['thumbnail']);

                    $photo = new TreatmentAttachment($request->safe()->only(['stage', 'caption']));
                    $photo->type = AttachmentType::Photo;
                    $photo->treatment_id = $treatment->getKey();
                    $photo->path = $path;
                    $photo->thumbnail_path = $thumbnailPath;
                    $photo->width = $processed['width'];
                    $photo->height = $processed['height'];
                    $photo->size = strlen($processed['image']);
                    $photo->uploaded_by = $request->user()->getKey();
                    $photo->save();

                    $this->audit->log(AuditAction::PhotoUploaded, $photo, [
                        'patient_id' => $patient->getKey(),
                        'treatment_id' => $treatment->getKey(),
                    ]);
                }
            });
        } catch (Throwable $exception) {
            // Nada fica pela metade: se uma foto falha, as já gravadas neste envio saem do disco.
            $this->vault->delete(...$stored);

            if ($exception instanceof InvalidPhotoException) {
                throw ValidationException::withMessages(['photos' => $exception->getMessage()]);
            }

            throw $exception;
        }

        $count = count($request->file('photos'));

        return redirect()->route('patients.treatments.show', [$patient, $treatment])
            ->with('success', $count === 1 ? 'Foto adicionada.' : "{$count} fotos adicionadas.");
    }

    public function show(Request $request, Patient $patient, Treatment $treatment, TreatmentAttachment $photo): Response
    {
        Gate::authorize('view', $treatment);

        $thumbnail = $request->boolean('miniatura');

        // A miniatura aparece dentro da página do atendimento, cujo acesso já
        // é auditado. Abrir a foto em tamanho real gera registro próprio.
        if (! $thumbnail) {
            $this->audit->log(AuditAction::PhotoViewed, $photo, [
                'patient_id' => $patient->getKey(),
                'treatment_id' => $treatment->getKey(),
            ]);
        }

        $contents = $this->vault->read($thumbnail ? $photo->thumbnail_path : $photo->path);

        return response($contents, 200, [
            'Content-Type' => 'image/jpeg',
            'Content-Length' => (string) strlen($contents),
            'Content-Disposition' => 'inline; filename="foto-'.$photo->getKey().'.jpg"',
        ]);
    }

    public function destroy(Patient $patient, Treatment $treatment, TreatmentAttachment $photo): RedirectResponse
    {
        Gate::authorize('detach', $treatment);

        DB::transaction(function () use ($patient, $treatment, $photo): void {
            // Remoção lógica: a foto some das telas, mas o arquivo é mantido.
            $photo->delete();

            $this->audit->log(AuditAction::PhotoDeleted, $photo, [
                'patient_id' => $patient->getKey(),
                'treatment_id' => $treatment->getKey(),
            ]);
        });

        return redirect()->route('patients.treatments.show', [$patient, $treatment])->with('success', 'Foto removida.');
    }
}
