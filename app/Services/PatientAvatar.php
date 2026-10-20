<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Exceptions\InvalidPhotoException;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Foto de identificação do paciente. Recodificada pelo PhotoProcessor e
 * guardada criptografada no cofre, como as demais fotos.
 *
 * Diferente da foto clínica, a foto trocada ou removida sai do disco: não é
 * prontuário, e guardar só a atual é o mínimo necessário para identificar.
 */
class PatientAvatar
{
    public function __construct(
        private readonly PhotoProcessor $processor,
        private readonly AttachmentVault $vault,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @throws ValidationException quando o arquivo não é uma imagem aceita
     */
    public function replace(Patient $patient, UploadedFile $file, User $user): void
    {
        try {
            $image = $this->processor->avatar($file);
        } catch (InvalidPhotoException $exception) {
            throw ValidationException::withMessages(['avatar' => $exception->getMessage()]);
        }

        $path = $this->vault->store($patient->getKey(), $image);

        try {
            $this->save($patient, $path, $user);
        } catch (Throwable $exception) {
            $this->vault->delete($path);

            throw $exception;
        }
    }

    public function remove(Patient $patient, User $user): void
    {
        // Sem foto, não há o que remover nem o que auditar.
        if ($patient->avatar_path !== null) {
            $this->save($patient, null, $user);
        }
    }

    public function read(Patient $patient): ?string
    {
        return $patient->avatar_path === null ? null : $this->vault->read($patient->avatar_path);
    }

    private function save(Patient $patient, ?string $path, User $user): void
    {
        $previous = DB::transaction(function () use ($patient, $path, $user): ?string {
            // Lê a foto atual com a linha travada: duas trocas simultâneas não
            // apagam a mesma foto antiga nem deixam a outra órfã no disco.
            $previous = Patient::query()->whereKey($patient->getKey())->lockForUpdate()->value('avatar_path');

            $patient->avatar_path = $path;
            $patient->save();

            // Só o nome do campo, nunca o arquivo ou o caminho.
            $this->audit->log(AuditAction::PatientUpdated, $patient, ['fields' => ['avatar']], $user);

            return $previous;
        });

        // A foto anterior só sai do disco depois que a troca foi gravada.
        if ($previous !== null) {
            $this->vault->delete($previous);
        }
    }
}
