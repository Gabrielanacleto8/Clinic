<?php

namespace App\Http\Controllers;

use App\Enums\AuditAction;
use App\Enums\FinancialEntryType;
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

/**
 * Abas A receber e A pagar: as parcelas em aberto de todos os meses,
 * separadas pelo prazo até o vencimento.
 */
class FinanceAccountsController extends Controller
{
    public const BUCKETS = [
        'vencidas' => 'Vencidas',
        'hoje' => 'Vencem hoje',
        'semana' => 'Próximos 7 dias',
        'mes' => 'De 8 a 30 dias',
        'depois' => 'Depois de 30 dias',
    ];

    public function __construct(private readonly AuditLogger $audit) {}

    public function receivable(Request $request): View
    {
        return $this->accounts($request, FinancialEntryType::Income);
    }

    public function payable(Request $request): View
    {
        return $this->accounts($request, FinancialEntryType::Expense);
    }

    private function accounts(Request $request, FinancialEntryType $type): View
    {
        Gate::authorize('viewAny', FinancialEntry::class);

        $filters = $request->validate([
            'faixa' => ['nullable', Rule::in(array_keys(self::BUCKETS))],
            'q' => ['nullable', 'string', 'max:100'],
        ]);
        $bucket = $filters['faixa'] ?? null;
        $term = trim((string) ($filters['q'] ?? ''));
        $isIncome = $type === FinancialEntryType::Income;

        // A lista a receber leva nome de paciente: leitura auditada, sem o termo buscado.
        $this->audit->log(AuditAction::FinanceViewed, null, ['view' => $isIncome ? 'receivable' : 'payable', 'patient_filter' => $term !== '']);

        $open = fn (): Builder => FinancialInstallment::query()
            ->whereNull('paid_at')
            ->whereHas('entry', fn ($entry) => $entry->where('type', $type->value))
            ->when($term !== '', fn ($query) => $query->whereHas('entry', fn ($entry) => $entry->where(
                fn ($inner) => $inner->where('description', 'like', '%'.addcslashes($term, '%_\\').'%')
                    ->when($isIncome, fn ($withPatient) => $withPatient->orWhereIn('patient_id', Patient::withTrashed()->search($term)->select('id'))),
            )));

        $bounds = $this->bounds();
        $totals = $this->bucketTotals($open(), $bounds);

        $installments = $open()
            ->when($bucket, fn ($query, string $key) => $this->inBucket($query, $key, $bounds))
            ->with(['entry' => fn ($query) => $query
                ->withCount('installments')
                ->withSum('installments as total_amount', 'amount')
                ->with(['patient' => fn ($patient) => $patient->withTrashed()->select(['id', 'name'])]),
            ])
            ->orderBy('due_date')
            ->orderBy('id')
            ->paginate(25)
            ->withQueryString();

        return view('finance.accounts', [
            'type' => $type,
            'isIncome' => $isIncome,
            'installments' => $installments,
            'totals' => $totals,
            'buckets' => self::BUCKETS,
            'filters' => ['faixa' => $bucket, 'q' => $term],
        ]);
    }

    /**
     * Datas que separam as faixas, a partir de hoje.
     *
     * @return array{today: string, week: string, month: string}
     */
    private function bounds(): array
    {
        $today = CarbonImmutable::createFromFormat('!Y-m-d', ClinicTime::today());

        return [
            'today' => $today->format('Y-m-d'),
            'week' => $today->addDays(7)->format('Y-m-d'),
            'month' => $today->addDays(30)->format('Y-m-d'),
        ];
    }

    /**
     * @param  Builder<FinancialInstallment>  $query
     * @param  array{today: string, week: string, month: string}  $bounds
     * @return Builder<FinancialInstallment>
     */
    private function inBucket(Builder $query, string $bucket, array $bounds): Builder
    {
        return match ($bucket) {
            'vencidas' => $query->where('due_date', '<', $bounds['today']),
            'hoje' => $query->where('due_date', $bounds['today']),
            'semana' => $query->where('due_date', '>', $bounds['today'])->where('due_date', '<=', $bounds['week']),
            'mes' => $query->where('due_date', '>', $bounds['week'])->where('due_date', '<=', $bounds['month']),
            default => $query->where('due_date', '>', $bounds['month']),
        };
    }

    /**
     * Valor e quantidade de parcelas de cada faixa, em uma consulta.
     *
     * @param  Builder<FinancialInstallment>  $query
     * @param  array{today: string, week: string, month: string}  $bounds
     * @return array<string, array{amount: float, count: int}>
     */
    private function bucketTotals(Builder $query, array $bounds): array
    {
        $case = 'case when due_date < ? then \'vencidas\' when due_date = ? then \'hoje\' when due_date <= ? then \'semana\' when due_date <= ? then \'mes\' else \'depois\' end';
        $bindings = [$bounds['today'], $bounds['today'], $bounds['week'], $bounds['month']];

        $rows = DB::query()
            ->fromSub($query->toBase()->selectRaw("{$case} as bucket, amount", $bindings), 'open')
            ->groupBy('bucket')
            ->selectRaw('bucket, sum(amount) as amount, count(*) as installments')
            ->get()
            ->keyBy('bucket');

        $totals = [];

        foreach (array_keys(self::BUCKETS) as $key) {
            $totals[$key] = ['amount' => (float) ($rows[$key]->amount ?? 0), 'count' => (int) ($rows[$key]->installments ?? 0)];
        }

        return $totals;
    }
}
