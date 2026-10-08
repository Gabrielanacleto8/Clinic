<?php

namespace App\Enums;

enum FinancialCategory: string
{
    case Treatment = 'treatment';
    case OtherIncome = 'other_income';

    case Supplies = 'supplies';
    case Rent = 'rent';
    case Payroll = 'payroll';
    case Taxes = 'taxes';
    case Marketing = 'marketing';
    case Equipment = 'equipment';
    case OtherExpense = 'other_expense';

    public function label(): string
    {
        return match ($this) {
            self::Treatment => 'Atendimento',
            self::OtherIncome => 'Outras receitas',
            self::Supplies => 'Insumos e produtos',
            self::Rent => 'Aluguel e condomínio',
            self::Payroll => 'Salários e pró-labore',
            self::Taxes => 'Impostos e taxas',
            self::Marketing => 'Marketing',
            self::Equipment => 'Equipamentos e manutenção',
            self::OtherExpense => 'Outras despesas',
        };
    }

    public function type(): FinancialEntryType
    {
        return match ($this) {
            self::Treatment, self::OtherIncome => FinancialEntryType::Income,
            default => FinancialEntryType::Expense,
        };
    }

    /**
     * Categorias que podem ser escolhidas em um lançamento manual do tipo dado.
     * "Atendimento" fica de fora: só nasce da finalização de um atendimento.
     *
     * @return array<string, string>
     */
    public static function optionsFor(FinancialEntryType $type): array
    {
        $options = [];

        foreach (self::cases() as $category) {
            if ($category->type() === $type && $category !== self::Treatment) {
                $options[$category->value] = $category->label();
            }
        }

        return $options;
    }
}
