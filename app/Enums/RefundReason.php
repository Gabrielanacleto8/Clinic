<?php

namespace App\Enums;

/**
 * Motivo do estorno, de uma lista fechada: texto livre poderia levar dado de
 * saúde para o financeiro, que não é criptografado.
 */
enum RefundReason: string
{
    case Withdrawal = 'withdrawal';
    case Dissatisfaction = 'dissatisfaction';
    case WrongCharge = 'wrong_charge';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Withdrawal => 'Desistência',
            self::Dissatisfaction => 'Insatisfação com o resultado',
            self::WrongCharge => 'Cobrança indevida ou a maior',
            self::Other => 'Outro motivo',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return array_combine(
            array_map(fn (self $reason) => $reason->value, self::cases()),
            array_map(fn (self $reason) => $reason->label(), self::cases()),
        );
    }
}
