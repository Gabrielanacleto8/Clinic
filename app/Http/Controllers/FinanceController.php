<?php

namespace App\Http\Controllers;

use App\Enums\FinancialEntryType;
use App\Models\FinancialEntry;
use App\Models\FinancialInstallment;
use App\Support\ClinicTime;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class FinanceController extends Controller
{
    private const TYPES = ['todos' => 'Receitas e despesas', 'receitas' => 'Só receitas', 'despesas' => 'Só despesas'];

    private const STATUSES = ['todas' => 'Todas as situações', 'pagas' => 'Pagas', 'pendentes' => 'Pendentes', 'vencidas' => 'Vencidas'];

    private const REVENUE_MONTHS = 6;

    public function index(Request $request): View
    {
        Gate::authorize('viewAny', FinancialEntry::class);

        $filters = $request->validate([
            'mes' => ['nullable', 'date_format:Y-m', 'after:2000-01', 'before:2100-01'],
            'tipo' => ['nullable', Rule::in(array_keys(self::TYPES))],
            'situacao' => ['nullable', Rule::in(array_keys(self::STATUSES))],
        ]);

        $month = CarbonImmutable::createFromFormat('!Y-m', $filters['mes'] ?? substr(ClinicTime::today(), 0, 7));
        $start = $month->format('Y-m-d');
        $end = $month->endOfMonth()->format('Y-m-d');
        $type = $filters['tipo'] ?? 'todos';
        $status = $filters['situacao'] ?? 'todas';

        return view('finance.index', [
            'month' => $month,
            'summary' => $this->summary($start, $end),
            'revenueByMonth' => $this->revenueByMonth($month),
            'installments' => $this->installments($start, $end, $type, $status),
            'filters' => ['mes' => $month->format('Y-m'), 'tipo' => $type, 'situacao' => $status],
            'typeOptions' => self::TYPES,
            'statusOptions' => self::STATUSES,
        ]);
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
     * Faturamento (receitas lançadas, pela data de competência) dos últimos meses.
     *
     * @return array<string, float> Mês (Y-m) => total, do mais antigo ao mais recente.
     */
    private function revenueByMonth(CarbonImmutable $month): array
    {
        $first = $month->subMonths(self::REVENUE_MONTHS - 1);

        $totals = DB::table('financial_entries as e')
            ->join('financial_installments as i', 'i.financial_entry_id', '=', 'e.id')
            ->where('e.type', FinancialEntryType::Income->value)
            ->whereBetween('e.issued_on', [$first->format('Y-m-d'), $month->endOfMonth()->format('Y-m-d')])
            ->groupByRaw("date_format(e.issued_on, '%Y-%m')")
            ->selectRaw("date_format(e.issued_on, '%Y-%m') as period, sum(i.amount) as total")
            ->pluck('total', 'period');

        $series = [];

        for ($offset = 0; $offset < self::REVENUE_MONTHS; $offset++) {
            $key = $first->addMonths($offset)->format('Y-m');
            $series[$key] = (float) ($totals[$key] ?? 0);
        }

        return $series;
    }

    /**
     * Parcelas que vencem ou foram pagas no período.
     *
     * @return LengthAwarePaginator<int, FinancialInstallment>
     */
    private function installments(string $start, string $end, string $type, string $status)
    {
        $today = ClinicTime::today();

        return FinancialInstallment::query()
            ->select('financial_installments.*')
            ->join('financial_entries', 'financial_entries.id', '=', 'financial_installments.financial_entry_id')
            ->with(['entry' => fn ($query) => $query->withCount('installments')->with('patient:id,name')])
            ->where(fn ($query) => $query->whereBetween('due_date', [$start, $end])->orWhereBetween('paid_at', [$start, $end]))
            ->when($type === 'receitas', fn ($query) => $query->where('financial_entries.type', FinancialEntryType::Income->value))
            ->when($type === 'despesas', fn ($query) => $query->where('financial_entries.type', FinancialEntryType::Expense->value))
            ->when($status === 'pagas', fn ($query) => $query->whereNotNull('paid_at'))
            ->when($status === 'pendentes', fn ($query) => $query->whereNull('paid_at')->where('due_date', '>=', $today))
            ->when($status === 'vencidas', fn ($query) => $query->whereNull('paid_at')->where('due_date', '<', $today))
            ->orderBy('due_date')
            ->orderBy('financial_installments.id')
            ->paginate(20)
            ->withQueryString();
    }
}
