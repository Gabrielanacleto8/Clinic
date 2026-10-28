<?php

namespace App\Support;

final class Cnpj
{
    public static function isValid(?string $value): bool
    {
        $cnpj = Cpf::digits($value);

        // Sequências repetidas passam no cálculo, mas não existem.
        if (strlen($cnpj) !== 14 || preg_match('/^(\d)\1{13}$/', $cnpj) === 1) {
            return false;
        }

        foreach ([12, 13] as $position) {
            $sum = 0;
            $weight = $position - 7;

            for ($i = 0; $i < $position; $i++) {
                $sum += (int) $cnpj[$i] * $weight;
                $weight = $weight === 2 ? 9 : $weight - 1;
            }

            $digit = $sum % 11 < 2 ? 0 : 11 - $sum % 11;

            if ((int) $cnpj[$position] !== $digit) {
                return false;
            }
        }

        return true;
    }

    public static function format(?string $value): string
    {
        $cnpj = Cpf::digits($value);

        if (strlen($cnpj) !== 14) {
            return $cnpj;
        }

        return sprintf('%s.%s.%s/%s-%s', substr($cnpj, 0, 2), substr($cnpj, 2, 3), substr($cnpj, 5, 3), substr($cnpj, 8, 4), substr($cnpj, 12, 2));
    }
}
