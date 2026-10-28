<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Aparência escolhida em cada aparelho: clara, escura ou a do sistema.
 * Guardada num cookie sem criptografia (é só preferência visual) e lida
 * pelo servidor, para a página já chegar com as cores certas.
 */
final class Theme
{
    public const COOKIE = 'theme';

    public const OPTIONS = ['light' => 'Claro', 'dark' => 'Escuro', 'system' => 'Igual ao do aparelho'];

    public static function current(Request $request): string
    {
        $value = $request->cookie(self::COOKIE);

        return is_string($value) && array_key_exists($value, self::OPTIONS) ? $value : 'system';
    }
}
