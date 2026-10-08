<?php

namespace App\Support;

/**
 * Aritmética de quantidades de estoque em milésimos inteiros, para não
 * acumular erro de ponto flutuante em somas e baixas.
 */
final class Quantity
{
    public const SCALE = 1000;

    public static function toThousandths(int|float|string $value): int
    {
        return (int) round(((float) $value) * self::SCALE);
    }

    public static function fromThousandths(int $thousandths): string
    {
        return number_format($thousandths / self::SCALE, 3, '.', '');
    }
}
