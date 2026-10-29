<?php

namespace App\Enums;

/**
 * Comportamento de uma despesa diante do movimento da clínica.
 */
enum CostBehavior: string
{
    case Fixed = 'fixed';
    case Variable = 'variable';

    public function label(): string
    {
        return match ($this) {
            self::Fixed => 'Fixa',
            self::Variable => 'Variável',
        };
    }
}
