<?php

namespace App\Services;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Guarda fotos e documentos criptografados (APP_KEY) em disco privado. Um vazamento do
 * disco ou de um backup, sozinho, não expõe os arquivos.
 */
class AttachmentVault
{
    /**
     * Grava o conteúdo e devolve o caminho interno. O nome é aleatório:
     * nada do nome original do arquivo é aproveitado.
     */
    public function store(int $patientId, string $contents): string
    {
        return $this->storeIn((string) $patientId, $contents);
    }

    /**
     * Mesma guarda, numa pasta própria: "tasks" para os anexos de tarefas.
     */
    public function storeIn(string $folder, string $contents): string
    {
        $path = $folder.'/'.Str::ulid().'.enc';

        $this->disk()->put($path, Crypt::encryptString($contents));

        return $path;
    }

    public function read(string $path): string
    {
        return Crypt::decryptString((string) $this->disk()->get($path));
    }

    public function delete(string ...$paths): void
    {
        $this->disk()->delete($paths);
    }

    private function disk(): Filesystem
    {
        return Storage::disk('patient_photos');
    }
}
