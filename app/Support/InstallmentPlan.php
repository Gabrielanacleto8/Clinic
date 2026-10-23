<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * Divide um valor em parcelas mensais.
 */
final class InstallmentPlan
{
    public const MAX_INSTALLMENTS = 12;

    public const TOO_MANY_MESSAGE = 'O valor não dá para tantas parcelas: cada uma precisa de pelo menos R$ 0,01.';

    /**
     * Cada parcela precisa de pelo menos um centavo: parcela zerada ficaria
     * pendente para sempre no financeiro.
     */
    public static function fits(string $total, int $count): bool
    {
        return $count >= 1 && (int) round(((float) $total) * 100) >= $count;
    }

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
