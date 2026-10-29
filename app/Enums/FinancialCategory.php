<?php

namespace App\Enums;

enum FinancialCategory: string
{
    case Treatment = 'treatment';
    case TreatmentPlan = 'treatment_plan';
    case OtherIncome = 'other_income';

    case Supplies = 'supplies';
    case Rent = 'rent';
    case Utilities = 'utilities';
    case Software = 'software';
    case Accounting = 'accounting';
    case Payroll = 'payroll';
    case Taxes = 'taxes';
    case Marketing = 'marketing';
    case Equipment = 'equipment';
    case CardFees = 'card_fees';
    case Commissions = 'commissions';
    case Refund = 'refund';
    case OtherExpense = 'other_expense';

    public function label(): string
    {
        return match ($this) {
            self::Treatment => 'Atendimento',
            self::TreatmentPlan => 'Pacote de sessões',
            self::OtherIncome => 'Outras receitas',
            self::Supplies => 'Insumos e produtos',
            self::Rent => 'Aluguel e condomínio',
            self::Utilities => 'Energia, água e internet',
            self::Software => 'Sistemas e assinaturas',
            self::Accounting => 'Contabilidade',
            self::Payroll => 'Salários e pró-labore',
            self::Taxes => 'Impostos e taxas',
            self::Marketing => 'Marketing',
            self::Equipment => 'Equipamentos e manutenção',
            self::CardFees => 'Taxas de cartão e maquininha',
            self::Commissions => 'Comissões e repasses',
            self::Refund => 'Estorno ao paciente',
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
     * Despesa fixa (o mesmo valor todo mês, haja ou não atendimento) ou
     * variável (acompanha o movimento). Receita e estorno não entram na conta.
     */
    public function costBehavior(): ?CostBehavior
    {
        return match ($this) {
            self::Rent, self::Utilities, self::Software, self::Accounting, self::Payroll => CostBehavior::Fixed,
            self::Supplies, self::Taxes, self::Marketing, self::Equipment, self::CardFees, self::Commissions, self::OtherExpense => CostBehavior::Variable,
            default => null,
        };
    }

    /**
     * Gerada pelo sistema: atendimento, pacote, estorno e repasse de comissão
     * nascem dos fluxos próprios, nunca de um lançamento manual.
     */
    public function isGeneratedOnly(): bool
    {
        return in_array($this, [self::Treatment, self::TreatmentPlan, self::Refund, self::Commissions], true);
    }

    /**
     * Categorias que podem ser escolhidas em um lançamento manual do tipo dado.
     *
     * @return array<string, string>
     */
    public static function optionsFor(FinancialEntryType $type): array
    {
        $options = [];

        foreach (self::cases() as $category) {
            if ($category->type() === $type && ! $category->isGeneratedOnly()) {
                $options[$category->value] = $category->label();
            }
        }

        return $options;
    }
}
