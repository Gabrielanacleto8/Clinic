<?php

namespace App\Support;

/**
 * Padrões para o operador LIKE a partir de um termo digitado pelo usuário.
 */
final class Like
{
    /**
     * "Contém o termo", com os curingas do próprio termo neutralizados.
     */
    public static function contains(string $term): string
    {
        return '%'.addcslashes($term, '\\%_').'%';
    }
}
