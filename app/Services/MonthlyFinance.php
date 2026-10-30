<?php

namespace App\Services;

use App\Enums\FinancialEntryType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Receitas e despesas mês a mês, com o mesmo mês do ano anterior para comparar.
 * Lançado é pela data do lançamento (competência); recebido, despesas e taxas
 * são pela data em que o dinheiro entrou ou saiu, como no fluxo de caixa.
 */
class MonthlyFinance
{
    public const WINDOWS = [6, 12];

    /**
     * Os últimos $count meses até $lastMonth, do mais antigo ao mais recente.
     * Cada mês traz issued, received, expenses, fees e result, e os mesmos
     * campos do mês um ano antes em "before".
     *
     * @return array<string, object> Mês (Y-m) => totais
     */
    public function months(CarbonImmutable $lastMonth, int $count): array
    {
        $first = $lastMonth->startOfMonth()->subMonths($count - 1);
        $last = $lastMonth->endOfMonth();
        // Uma consulta por fonte cobre a janela e o mesmo trecho um ano antes.
        $from = $first->subYear()->format('Y-m-d');
        $until = $last->format('Y-m-d');

        $issued = $this->byMonth(
            DB::table('financial_entries as e')
                ->join('financial_installments as i', 'i.financial_entry_id', '=', 'e.id')
                ->where('e.type', FinancialEntryType::Income->value),
            'e.issued_on', 'sum(i.amount)', $from, $until,
        );

        $paid = DB::table('financial_installments as i')
            ->join('financial_entries as e', 'e.id', '=', 'i.financial_entry_id')
            ->whereBetween('i.paid_at', [$from, $until])
            ->groupByRaw("date_format(i.paid_at, '%Y-%m')")
            ->selectRaw("date_format(i.paid_at, '%Y-%m') as period")
            ->selectRaw('coalesce(sum(case when e.type = ? then i.amount end), 0) as received', [FinancialEntryType::Income->value])
            ->selectRaw('coalesce(sum(case when e.type = ? then i.amount end), 0) as expenses', [FinancialEntryType::Expense->value])
            ->get()
            ->keyBy('period');

        // Taxa da operadora sai no mês em que o valor caiu no extrato.
        $fees = $this->byMonth(
            DB::table('financial_installments as i')->whereNotNull('i.fee'),
            'i.reconciled_on', 'sum(i.fee)', $from, $until,
        );

        $totals = fn (string $period) => $this->totals(
            (float) ($issued[$period] ?? 0),
            (float) ($paid[$period]->received ?? 0),
            (float) ($paid[$period]->expenses ?? 0),
            (float) ($fees[$period] ?? 0),
        );

        $series = [];

        for ($month = $first; $month->lessThanOrEqualTo($last); $month = $month->addMonth()) {
            $period = $month->format('Y-m');
            $series[$period] = $totals($period);
            $series[$period]->before = $totals($month->subYear()->format('Y-m'));
        }

        return $series;
    }

    /**
     * @return Collection<string, float>
     */
    private function byMonth(Builder $query, string $dateColumn, string $sum, string $from, string $until): Collection
    {
        return $query
            ->whereBetween($dateColumn, [$from, $until])
            ->groupByRaw("date_format({$dateColumn}, '%Y-%m')")
            ->selectRaw("date_format({$dateColumn}, '%Y-%m') as period, {$sum} as total")
            ->pluck('total', 'period')
            ->map(fn ($total) => (float) $total);
    }

    private function totals(float $issued, float $received, float $expenses, float $fees): object
    {
        return (object) [
            'issued' => $issued,
            'received' => $received,
            'expenses' => $expenses,
            'fees' => $fees,
            // Resultado de caixa: o que entrou menos o que saiu, taxas incluídas.
            'result' => $received - $expenses - $fees,
        ];
    }
}
