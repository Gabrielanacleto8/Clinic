<?php

namespace App\Support;

use RuntimeException;

/**
 * Índice cego: HMAC determinístico que permite busca exata e restrição de
 * unicidade sobre um valor guardado criptografado, sem expor o valor.
 */
final class BlindIndex
{
    public static function for(string $value): string
    {
        $key = (string) config('clinic.blind_index_key');

        if (strlen($key) < 32) {
            throw new RuntimeException('BLIND_INDEX_KEY ausente ou curta demais. Gere uma com: openssl rand -hex 32');
        }

        return hash_hmac('sha256', $value, $key);
    }
}
