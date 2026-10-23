<?php

namespace App\Http\Controllers;

use App\Enums\AuditAction;
use App\Enums\FinancialEntryType;
use App\Enums\PaymentMethod;
use App\Models\FinancialEntry;
use App\Models\FinancialInstallment;
use App\Models\Patient;
use App\Services\AuditLogger;
use App\Support\ClinicTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class FinanceController extends Controller
{
    private const TYPES = ['todos' => 'Receitas e despesas', 'receitas' => 'Só receitas', 'despesas' => 'Só despesas'];

    private const STATUSES = [
        'todas' => 'Todas as situações',
        'pagas' => 'Pagas',
        'abertas' => 'Em aberto (a vencer e vencidas)',
        'pendentes' => 'A vencer',
        'vencidas' => 'Vencidas no período',
        'vencidas_todas' => 'Vencidas (todos os meses)',
    ];

    private const REVENUE_MONTHS = 6;

    public function index(Request $request, AuditLogger $audit): View
    {
        Gate::authorize('viewAny', FinancialEntry::class);

        $filters = $request->validate([
            // "mes" é o atalho dos links do Início e do faturamento por período.
            'mes' => ['nullable', 'date_format:Y-m', 'after:2000-01', 'before:2100-01'],
            'de' => ['nullable', 'date_format:Y-m-d', 'after:2000-01-01', 'before:2100-01-01'],
            'ate' => ['nullable', 'date_format:Y-m-d', 'after:2000-01-01', 'before:2100-01-01', 'after_or_equal:de'],
            'tipo' => ['nullable', Rule::in(array_keys(self::TYPES))],
            'situacao' => ['nullable', Rule::in(array_keys(self::STATUSES))],
            'paciente' => ['nullable', 'string', 'max:100'],
        ], [], ['mes' => 'mês', 'de' => 'data inicial', 'ate' => 'data final', 'tipo' => 'tipo', 'situacao' => 'situação', 'paciente' => 'paciente']);

        [$start, $end] = $this->period($filters);
        $type = $filters['tipo'] ?? 'todos';
        $status = $filters['situacao'] ?? 'todas';
        $patientTerm = trim((string) ($filters['paciente'] ?? ''));

        // Os lançamentos levam nome de paciente e valores: leitura auditada. Só os filtros, nunca o nome buscado.
        $audit->log(AuditAction::FinanceViewed, null, ['from' => $start, 'to' => $end, 'type' => $type, 'status' => $status, 'patient_filter' => $patientTerm !== '']);

        $summary = $this->summary($start, $end);
        [$previousStart, $previousEnd] = $this->previousPeriod($start, $end);
        $month = CarbonImmutable::createFromFormat('!Y-m-d', $start);
        $listing = $this->listing($start, $end, $type, $status, $patientTerm);

        return view('finance.index', [
            'month' => $month,
            'start' => $start,
            'end' => $end,
            'isWholeMonth' => $start === $month->startOfMonth()->format('Y-m-d') && $end === $month->endOfMonth()->format('Y-m-d'),
            'summary' => $summary,
            'previous' => $this->summary($previousStart, $previousEnd),
            'receivedByMethod' => $this->receivedByMethod($start, $end),
            'revenueByMonth' => $this->revenueByMonth($month),
            'installments' => (clone $listing)->paginate(20)->withQueryString(),
            'listingTotal' => (float) (clone $listing)->sum('financial_installments.amount'),
            'filters' => ['de' => $start, 'ate' => $end, 'tipo' => $type, 'situacao' => $status, 'paciente' => $patientTerm],
            'typeOptions' => self::TYPES,
            'statusOptions' => self::STATUSES,
            'paymentMethods' => PaymentMethod::options(),
        ]);
    }

    /**
     * Início e fim do período (Y-m-d): "de"/"até", ou o mês informado, ou o mês corrente.
     *
     * @param  array<string, string>  $filters
     * @return array{string, string}
     */
    private function period(array $filters): array
    {
        if (isset($filters['de']) || isset($filters['ate'])) {
            $start = $filters['de'] ?? CarbonImmutable::createFromFormat('!Y-m-d', $filters['ate'])->startOfMonth()->format('Y-m-d');
            $end = $filters['ate'] ?? CarbonImmutable::createFromFormat('!Y-m-d', $filters['de'])->endOfMonth()->format('Y-m-d');

            return [$start, $end];
        }

        $month = CarbonImmutable::createFromFormat('!Y-m', $filters['mes'] ?? substr(ClinicTime::today(), 0, 7));

        return [$month->format('Y-m-d'), $month->endOfMonth()->format('Y-m-d')];
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
     * Parcelas que vencem ou foram pagas no período, com os filtros. A mesma
     * consulta serve à página e ao total do rodapé.
     *
     * @return Builder<FinancialInstallment>
     */
    private function listing(string $start, string $end, string $type, string $status, string $patientTerm): Builder
    {
        $today = ClinicTime::today();

        return FinancialInstallment::query()
            ->select('financial_installments.*')
            ->join('financial_entries', 'financial_entries.id', '=', 'financial_installments.financial_entry_id')
            // Paciente arquivado continua identificado no lançamento dele. O total e a
            // existência de parcela paga decidem as ações da linha sem consulta extra.
            ->with(['entry' => fn ($query) => $query
                ->withCount('installments')
                ->withSum('installments as total_amount', 'amount')
                ->withExists(['installments as has_paid_installment' => fn ($paid) => $paid->whereNotNull('paid_at')])
                ->with(['patient' => fn ($patient) => $patient->withTrashed()->select(['id', 'name'])]),
            ])
            // "Vencidas (todos os meses)" ignora o período: é a lista de cobrança.
            ->when($status !== 'vencidas_todas', fn ($query) => $query->where(
                fn ($inner) => $inner->whereBetween('due_date', [$start, $end])->orWhereBetween('paid_at', [$start, $end]),
            ))
            ->when($type === 'receitas', fn ($query) => $query->where('financial_entries.type', FinancialEntryType::Income->value))
            ->when($type === 'despesas', fn ($query) => $query->where('financial_entries.type', FinancialEntryType::Expense->value))
            ->when($status === 'pagas', fn ($query) => $query->whereNotNull('paid_at'))
            ->when($status === 'abertas', fn ($query) => $query->whereNull('paid_at'))
            ->when($status === 'pendentes', fn ($query) => $query->whereNull('paid_at')->where('due_date', '>=', $today))
            ->when(in_array($status, ['vencidas', 'vencidas_todas'], true), fn ($query) => $query->whereNull('paid_at')->where('due_date', '<', $today))
            ->when($patientTerm !== '', fn ($query) => $query->whereIn(
                'financial_entries.patient_id',
                Patient::withTrashed()->search($patientTerm)->select('id'),
            ))
            ->orderBy('due_date')
            ->orderBy('financial_installments.id');
    }
}
