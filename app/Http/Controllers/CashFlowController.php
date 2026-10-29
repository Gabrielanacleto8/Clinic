<?php

namespace App\Http\Controllers;

use App\Enums\FinancialEntryType;
use App\Http\Requests\FinanceFilterRequest;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Aba Fluxo de caixa: o que entrou e saiu em cada dia do período, com o
 * saldo acumulado desde o início do sistema, e o que está previsto (parcelas
 * em aberto que vencem no período).
 */
class CashFlowController extends Controller
{
    public function __invoke(FinanceFilterRequest $request): View
    {
        [$start, $end] = $request->period();
        $income = FinancialEntryType::Income->value;
        $expense = FinancialEntryType::Expense->value;

        $paid = DB::table('financial_installments as i')
            ->join('financial_entries as e', 'e.id', '=', 'i.financial_entry_id')
            ->whereBetween('i.paid_at', [$start, $end])
            ->groupBy('i.paid_at')
            ->selectRaw('i.paid_at as day')
            ->selectRaw('coalesce(sum(case when e.type = ? then i.amount end), 0) as total_in', [$income])
            ->selectRaw('coalesce(sum(case when e.type = ? then i.amount end), 0) as total_out', [$expense])
            ->get()
            ->keyBy(fn ($row) => substr((string) $row->day, 0, 10));

        // Taxa da operadora sai no dia em que o valor caiu no extrato.
        $fees = DB::table('financial_installments')
            ->whereBetween('reconciled_on', [$start, $end])
            ->whereNotNull('fee')
            ->groupBy('reconciled_on')
            ->selectRaw('reconciled_on as day, sum(fee) as total')
            ->pluck('total', 'day')
            ->mapWithKeys(fn ($total, $day) => [substr((string) $day, 0, 10) => (float) $total]);

        $planned = DB::table('financial_installments as i')
            ->join('financial_entries as e', 'e.id', '=', 'i.financial_entry_id')
            ->whereNull('i.paid_at')
            ->whereBetween('i.due_date', [$start, $end])
            ->groupBy('i.due_date')
            ->selectRaw('i.due_date as day')
            ->selectRaw('coalesce(sum(case when e.type = ? then i.amount end), 0) as total_in', [$income])
            ->selectRaw('coalesce(sum(case when e.type = ? then i.amount end), 0) as total_out', [$expense])
            ->get()
            ->keyBy(fn ($row) => substr((string) $row->day, 0, 10));

        $opening = $this->balanceBefore($start);
        $balance = $opening;
        $days = [];

        for ($day = CarbonImmutable::createFromFormat('!Y-m-d', $start); $day->format('Y-m-d') <= $end; $day = $day->addDay()) {
            $key = $day->format('Y-m-d');
            $in = (float) ($paid[$key]->total_in ?? 0);
            $out = (float) ($paid[$key]->total_out ?? 0);
            $fee = (float) ($fees[$key] ?? 0);
            $plannedIn = (float) ($planned[$key]->total_in ?? 0);
            $plannedOut = (float) ($planned[$key]->total_out ?? 0);

            if ($in == 0 && $out == 0 && $fee == 0 && $plannedIn == 0 && $plannedOut == 0) {
                continue;
            }

            $balance += $in - $out - $fee;
            $days[] = (object) [
                'day' => $day,
                'in' => $in,
                'out' => $out,
                'fees' => $fee,
                'net' => $in - $out - $fee,
                'balance' => $balance,
                'planned_in' => $plannedIn,
                'planned_out' => $plannedOut,
            ];
        }

        $totals = (object) [
            'in' => array_sum(array_column($days, 'in')),
            'out' => array_sum(array_column($days, 'out')),
            'fees' => array_sum(array_column($days, 'fees')),
            'planned_in' => array_sum(array_column($days, 'planned_in')),
            'planned_out' => array_sum(array_column($days, 'planned_out')),
        ];

        return view('finance.cashflow', [
            'start' => $start,
            'end' => $end,
            'days' => $days,
            'opening' => $opening,
            'closing' => $balance,
            'totals' => $totals,
            'projected' => $balance + $totals->planned_in - $totals->planned_out,
        ]);
    }

    /**
     * Saldo de tudo o que entrou e saiu antes do período, taxas incluídas.
     */
    private function balanceBefore(string $start): float
    {
        $row = DB::table('financial_installments as i')
            ->join('financial_entries as e', 'e.id', '=', 'i.financial_entry_id')
            ->where('i.paid_at', '<', $start)
            ->selectRaw('coalesce(sum(case when e.type = ? then i.amount else -i.amount end), 0) as balance', [FinancialEntryType::Income->value])
            ->first();

        $fees = (float) DB::table('financial_installments')->where('reconciled_on', '<', $start)->sum('fee');

        return round((float) $row->balance - $fees, 2);
    }
}
