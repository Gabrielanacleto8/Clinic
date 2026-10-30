<?php

namespace App\Http\Controllers;

use App\Http\Requests\FinanceAnalysisRequest;
use App\Services\MonthlyFinance;
use Illuminate\View\View;

/**
 * Aba Análises › Comparativo mensal: lançado, recebido, despesas e resultado
 * de cada mês, contra o mesmo mês do ano anterior. Só totais, sem paciente.
 */
class FinanceAnalysisController extends Controller
{
    public function __invoke(FinanceAnalysisRequest $request, MonthlyFinance $monthly): View
    {
        $lastMonth = $request->lastMonth();
        $window = $request->window();

        return view('finance.analysis', [
            'lastMonth' => $lastMonth,
            'window' => $window,
            'windows' => MonthlyFinance::WINDOWS,
            'months' => $monthly->months($lastMonth, $window),
        ]);
    }
}
