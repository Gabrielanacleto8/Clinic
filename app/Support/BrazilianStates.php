<?php

namespace App\Support;

final class BrazilianStates
{
    public const CODES = [
        'AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MT', 'MS', 'MG', 'PA',
        'PB', 'PR', 'PE', 'PI', 'RJ', 'RN', 'RS', 'RO', 'RR', 'SC', 'SP', 'SE', 'TO',
    ];

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return array_combine(self::CODES, self::CODES);
    }
}
