<?php

namespace App\Support;

/**
 * Telefone brasileiro guardado só com dígitos (DDD + número).
 */
final class Phone
{
    public static function format(?string $phone): string
    {
        $phone = (string) $phone;

        return match (strlen($phone)) {
            11 => sprintf('(%s) %s-%s', substr($phone, 0, 2), substr($phone, 2, 5), substr($phone, 7)),
            10 => sprintf('(%s) %s-%s', substr($phone, 0, 2), substr($phone, 2, 4), substr($phone, 6)),
            default => $phone,
        };
    }
}
