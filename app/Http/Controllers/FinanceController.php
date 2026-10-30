<?php

namespace App\Http\Controllers;

use App\Enums\AuditAction;
use App\Enums\FinancialEntryType;
use App\Enums\PaymentMethod;
use App\Http\Requests\FinanceFilterRequest;
use App\Http\Requests\FinancialEntryRequest;
use App\Services\AuditLogger;
use App\Services\FinanceListing;
use App\Services\MonthlyFinance;
use App\Support\ClinicTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class FinanceController extends Controller
{
    private const CHART_MONTHS = 6;

    public function index(FinanceFilterRequest $request, AuditLogger $audit, FinanceListing $listing, MonthlyFinance $monthly): View
    {
        [$start, $end] = $request->period();
        $type = $request->type();
        $status = $request->status();
        $patientTerm = $request->patientTerm();

        // Os lançamentos levam nome de paciente e valores: leitura auditada. Só os filtros, nunca o nome buscado.
        $audit->log(AuditAction::FinanceViewed, null, ['from' => $start, 'to' => $end, 'type' => $type, 'status' => $status, 'patient_filter' => $patientTerm !== '']);

        $summary = $this->summary($start, $end);
        [$previousStart, $previousEnd] = $this->previousPeriod($start, $end);
        $month = CarbonImmutable::createFromFormat('!Y-m-d', $start);
        $installments = $listing->query($start, $end, $type, $status, $patientTerm);

        return view('finance.index', [
            'month' => $month,
            'start' => $start,
            'end' => $end,
            'isWholeMonth' => $start === $month->startOfMonth()->format('Y-m-d') && $end === $month->endOfMonth()->format('Y-m-d'),
            'summary' => $summary,
            'previous' => $this->summary($previousStart, $previousEnd),
            'receivedByMethod' => $this->receivedByMethod($start, $end),
            // Gráfico de receitas e despesas: os seis meses até o do período.
            'flow' => $monthly->months($month, self::CHART_MONTHS),
            // Paciente arquivado continua identificado no lançamento dele. O total e a
            // existência de parcela paga decidem as ações da linha sem consulta extra.
            'installments' => (clone $installments)
                ->with(['entry' => fn ($query) => $query
                    ->withCount('installments')
                    ->withSum('installments as total_amount', 'amount')
                    ->withExists(['installments as has_paid_installment' => fn ($paid) => $paid->whereNotNull('paid_at')])
                    ->with(['patient' => fn ($patient) => $patient->withTrashed()->select(['id', 'name'])]),
                ])
                ->paginate(20)
                ->withQueryString(),
            'listingTotal' => (float) (clone $installments)->sum('financial_installments.amount'),
            'filters' => ['de' => $start, 'ate' => $end, 'tipo' => $type, 'situacao' => $status, 'paciente' => $patientTerm],
            'typeOptions' => FinanceFilterRequest::TYPES,
            'statusOptions' => FinanceFilterRequest::STATUSES,
            'paymentMethods' => PaymentMethod::options(),
            // A janela de nova receita voltou com erro: reabre com o paciente escolhido.
            'entryPatient' => $request->old('_dialog') === 'entry-income' ? FinancialEntryRequest::oldPatient($request) : null,
        ]);
    }

    /**
     * Período de mesma duração, logo antes: um mês inteiro compara com o mês
     * anterior; dez dias comparam com os dez dias anteriores.
     *
     * @return array{string, string}
     */
    private function previousPeriod(string $start, string $end): array
    {
        $from = CarbonImmutable::createFromFormat('!Y-m-d', $start);
        $until = CarbonImmutable::createFromFormat('!Y-m-d', $end);

        if ($from->day === 1 && $until->equalTo($from->endOfMonth())) {
            $previous = $from->subMonthNoOverflow();

            return [$previous->format('Y-m-d'), $previous->endOfMonth()->format('Y-m-d')];
        }

        $days = $from->diffInDays($until) + 1;

        return [$from->subDays($days)->format('Y-m-d'), $from->subDay()->format('Y-m-d')];
    }

    /**
     * Totais do período em uma única consulta.
     *
     * @return array{received: float, receivable: float, expenses: float, payable: float, revenue: float, overdue: float, balance: float}
     */
    private function summary(string $start, string $end): array
    {
        $today = ClinicTime::today();
        $income = FinancialEntryType::Income->value;
        $expense = FinancialEntryType::Expense->value;

        $row = DB::table('financial_installments as i')
            ->join('financial_entries as e', 'e.id', '=', 'i.financial_entry_id')
            ->selectRaw('coalesce(sum(case when e.type = ? and i.paid_at between ? and ? then i.amount end), 0) as received', [$income, $start, $end])
            ->selectRaw('coalesce(sum(case when e.type = ? and i.paid_at is null and i.due_date between ? and ? then i.amount end), 0) as receivable', [$income, $start, $end])
            ->selectRaw('coalesce(sum(case when e.type = ? and i.paid_at between ? and ? then i.amount end), 0) as expenses', [$expense, $start, $end])
            ->selectRaw('coalesce(sum(case when e.type = ? and i.paid_at is null and i.due_date between ? and ? then i.amount end), 0) as payable', [$expense, $start, $end])
            ->selectRaw('coalesce(sum(case when e.type = ? and e.issued_on between ? and ? then i.amount end), 0) as revenue', [$income, $start, $end])
            ->selectRaw('coalesce(sum(case when e.type = ? and i.paid_at is null and i.due_date < ? then i.amount end), 0) as overdue', [$income, $today])
            ->first();

        $summary = array_map('floatval', (array) $row);

        return [...$summary, 'balance' => $summary['received'] - $summary['expenses']];
    }

    /**
     * Receitas recebidas no período, por forma de pagamento: o fechamento do caixa.
     *
     * @return array<string, float> Forma (valor do enum) => total, só as que receberam algo.
     */
    private function receivedByMethod(string $start, string $end): array
    {
        return DB::table('financial_installments as i')
            ->join('financial_entries as e', 'e.id', '=', 'i.financial_entry_id')
            ->where('e.type', FinancialEntryType::Income->value)
            ->whereBetween('i.paid_at', [$start, $end])
            ->groupBy('e.payment_method')
            ->orderByDesc('total')
            ->selectRaw('e.payment_method, sum(i.amount) as total')
            ->pluck('total', 'payment_method')
            ->map(fn ($total) => (float) $total)
            ->all();
    }
}
