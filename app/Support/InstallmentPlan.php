<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * Divide um valor em parcelas mensais.
 */
final class InstallmentPlan
{
    public const MAX_INSTALLMENTS = 12;

    /**
     * A conta é feita em centavos inteiros. O que sobra da divisão vai para a
     * primeira parcela, de modo que a soma das parcelas é sempre exatamente o total.
     *
     * @return list<array{number: int, amount: string, due_date: string}>
     */
    public static function split(string $total, int $count, string $firstDueDate): array
    {
        $cents = (int) round(((float) $total) * 100);
        $base = intdiv($cents, $count);
        $remainder = $cents - $base * $count;
        $first = CarbonImmutable::createFromFormat('Y-m-d', $firstDueDate)->startOfDay();

        $installments = [];

        for ($number = 1; $number <= $count; $number++) {
            $installments[] = [
                'number' => $number,
                'amount' => number_format(($base + ($number === 1 ? $remainder : 0)) / 100, 2, '.', ''),
                // Sem "transbordo": 31/01 + 1 mês cai em 28/02, não em março.
                'due_date' => $first->addMonthsNoOverflow($number - 1)->format('Y-m-d'),
            ];
        }

        return $installments;
    }
}
