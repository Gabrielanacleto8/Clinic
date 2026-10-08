<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case Pix = 'pix';
    case Cash = 'cash';
    case DebitCard = 'debit_card';
    case CreditCard = 'credit_card';
    case BankSlip = 'bank_slip';
    case BankTransfer = 'bank_transfer';

    public function label(): string
    {
        return match ($this) {
            self::Pix => 'Pix',
            self::Cash => 'Dinheiro',
            self::DebitCard => 'Cartão de débito',
            self::CreditCard => 'Cartão de crédito',
            self::BankSlip => 'Boleto',
            self::BankTransfer => 'Transferência',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return array_column(
            array_map(fn (self $method) => ['value' => $method->value, 'label' => $method->label()], self::cases()),
            'label',
            'value',
        );
    }
}
