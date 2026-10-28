<?php

namespace App\Enums;

enum FinancialCategory: string
{
    case Treatment = 'treatment';
    case TreatmentPlan = 'treatment_plan';
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
            self::TreatmentPlan => 'Pacote de sessões',
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
            self::Treatment, self::TreatmentPlan, self::OtherIncome => FinancialEntryType::Income,
            default => FinancialEntryType::Expense,
        };
    }

    /**
     * Categorias que podem ser escolhidas em um lançamento manual do tipo dado.
     * "Atendimento" e "Pacote de sessões" ficam de fora: só nascem do registro
     * de um atendimento e da aprovação de um orçamento.
     *
     * @return array<string, string>
     */
    public static function optionsFor(FinancialEntryType $type): array
    {
        $options = [];

        foreach (self::cases() as $category) {
            if ($category->type() === $type && ! in_array($category, [self::Treatment, self::TreatmentPlan], true)) {
                $options[$category->value] = $category->label();
            }
        }

        return $options;
    }
}
