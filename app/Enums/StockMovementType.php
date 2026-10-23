<?php

namespace App\Enums;

enum StockMovementType: string
{
    case Entry = 'entry';
    case Exit = 'exit';
    case Consumption = 'consumption';
    case Loss = 'loss';
    case Adjustment = 'adjustment';
    // Devolução ao estoque do que um atendimento anulado baixou.
    case Reversal = 'reversal';

    /**
     * Tipos que um usuário lança à mão sobre um lote existente. A entrada tem
     * formulário próprio e o consumo só nasce do registro de um atendimento.
     *
     * @return list<self>
     */
    public static function manual(): array
    {
        return [self::Exit, self::Loss, self::Adjustment];
    }

    public function label(): string
    {
        return match ($this) {
            self::Entry => 'Entrada',
            self::Exit => 'Saída',
            self::Consumption => 'Consumo em procedimento',
            self::Loss => 'Perda',
            self::Adjustment => 'Ajuste',
            self::Reversal => 'Estorno de atendimento',
        };
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::Entry => 'bg-emerald-100 text-emerald-800',
            self::Exit => 'bg-slate-200 text-slate-700',
            self::Consumption => 'bg-sky-100 text-sky-800',
            self::Loss => 'bg-red-100 text-red-800',
            self::Adjustment => 'bg-amber-100 text-amber-800',
            self::Reversal => 'bg-violet-100 text-violet-800',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return array_column(
            array_map(fn (self $type) => ['value' => $type->value, 'label' => $type->label()], self::cases()),
            'label',
            'value',
        );
    }
}
