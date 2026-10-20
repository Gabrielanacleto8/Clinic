<?php

namespace App\Services;

use App\Enums\AttachmentType;
use App\Enums\AuditAction;
use App\Exceptions\InvalidPhotoException;
use App\Models\Patient;
use App\Models\PatientAttachment;
use App\Models\Treatment;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

/**
 * Grava um envio de fotos de um paciente, de um atendimento ou avulsas:
 * recodifica cada imagem, guarda no cofre e registra os metadados e a auditoria.
 */
class PhotoUploader
{
    public function __construct(
        private readonly PhotoProcessor $processor,
        private readonly AttachmentVault $vault,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  list<UploadedFile>  $files
     * @param  array<string, mixed>  $attributes  stage, caption e, sem atendimento, taken_on
     *
     * @throws ValidationException quando um dos arquivos não é uma imagem aceita
     */
    public function upload(array $files, array $attributes, Patient $patient, ?Treatment $treatment, User $user): void
    {
        // patient_id é gravado na foto; o serviço, e não só a rota, garante a coerência.
        if ($treatment !== null && $treatment->patient_id !== $patient->getKey()) {
            throw new InvalidArgumentException('O atendimento não é deste paciente.');
        }

        $stored = [];

        try {
            DB::transaction(function () use ($files, $attributes, $patient, $treatment, $user, &$stored): void {
                foreach ($files as $file) {
                    $processed = $this->processor->process($file);

                    $stored[] = $path = $this->vault->store($patient->getKey(), $processed['image']);
                    $stored[] = $thumbnailPath = $this->vault->store($patient->getKey(), $processed['thumbnail']);

                    $photo = new PatientAttachment($attributes);
                    $photo->type = AttachmentType::Photo;
                    $photo->patient_id = $patient->getKey();
                    $photo->treatment_id = $treatment?->getKey();
                    $photo->path = $path;
                    $photo->thumbnail_path = $thumbnailPath;
                    $photo->width = $processed['width'];
                    $photo->height = $processed['height'];
                    $photo->size = strlen($processed['image']);
                    $photo->uploaded_by = $user->getKey();
                    $photo->save();

                    $this->audit->log(AuditAction::PhotoUploaded, $photo, [
                        'patient_id' => $patient->getKey(),
                        'treatment_id' => $treatment?->getKey(),
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
    }
}
