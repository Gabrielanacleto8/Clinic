<?php

namespace App\Support;

/**
 * Configuração mínima para o sistema rodar em produção sem expor dados.
 *
 * As mensagens citam só o nome da variável, nunca o valor.
 */
final class ProductionConfig
{
    /**
     * Problemas que impedem o uso em produção. A versão completa também
     * confere a pasta das fotos, o que envolve acesso ao disco e fica para o
     * comando clinic:check.
     *
     * @return list<string>
     */
    public static function problems(bool $checkStorage = false): array
    {
        $problems = [];

        if (blank(config('app.key'))) {
            $problems[] = 'APP_KEY não está definida. Sem ela nada criptografado pode ser lido.';
        }

        if (strlen((string) config('clinic.blind_index_key')) < 32) {
            $problems[] = 'BLIND_INDEX_KEY ausente ou curta demais (mínimo de 32 caracteres). Gere com: openssl rand -hex 32';
        }

        if (! str_starts_with((string) config('app.url'), 'https://')) {
            $problems[] = 'APP_URL precisa começar com https://. O sistema só funciona por HTTPS em produção.';
        }

        if ($checkStorage) {
            $root = (string) config('filesystems.disks.patient_photos.root');

            if (! is_dir($root) || ! is_writable($root)) {
                $problems[] = 'A pasta das fotos e documentos (PATIENT_PHOTOS_PATH) não existe ou não permite gravação.';
            }
        }

        return $problems;
    }
}
