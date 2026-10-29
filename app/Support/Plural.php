<?php

namespace App\Support;

/**
 * Quantidade com a palavra no singular ou no plural: "1 sessão", "3 sessões".
 */
final class Plural
{
    public static function of(int $count, string $one, string $many): string
    {
        return $count.' '.(abs($count) === 1 ? $one : $many);
    }
}
