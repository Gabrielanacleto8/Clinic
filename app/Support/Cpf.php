<?php

namespace App\Support;

final class Cpf
{
    public static function digits(?string $value): string
    {
        return preg_replace('/\D/', '', (string) $value) ?? '';
    }

    public static function isValid(?string $value): bool
    {
        $cpf = self::digits($value);

        // Sequências repetidas (000.000.000-00 etc.) passam no cálculo, mas não existem.
        if (strlen($cpf) !== 11 || preg_match('/^(\d)\1{10}$/', $cpf) === 1) {
            return false;
        }

        foreach ([9, 10] as $position) {
            $sum = 0;

            for ($i = 0; $i < $position; $i++) {
                $sum += (int) $cpf[$i] * ($position + 1 - $i);
            }

            $digit = (10 * $sum) % 11 % 10;

            if ((int) $cpf[$position] !== $digit) {
                return false;
            }
        }

        return true;
    }

    public static function format(?string $value): string
    {
        $cpf = self::digits($value);

        if (strlen($cpf) !== 11) {
            return $cpf;
        }

        return sprintf('%s.%s.%s-%s', substr($cpf, 0, 3), substr($cpf, 3, 3), substr($cpf, 6, 3), substr($cpf, 9, 2));
    }

    /**
     * Exibe apenas os dígitos centrais, para listagens.
     */
    public static function mask(?string $value): string
    {
        $cpf = self::digits($value);

        if (strlen($cpf) !== 11) {
            return '';
        }

        return sprintf('***.%s.%s-**', substr($cpf, 3, 3), substr($cpf, 6, 3));
    }
}
