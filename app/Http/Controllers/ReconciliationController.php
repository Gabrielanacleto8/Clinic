<?php

namespace App\Http\Controllers;

use App\Enums\AuditAction;
use App\Enums\FinancialEntryType;
use App\Enums\PaymentMethod;
use App\Http\Requests\ReconcileRequest;
use App\Models\FinancialEntry;
use App\Models\FinancialInstallment;
use App\Services\AuditLogger;
use App\Support\ClinicTime;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Aba Conciliação: os recebimentos fora do dinheiro conferidos no extrato
 * do banco ou da maquininha, com a data em que caíram e a taxa cobrada.
 */
class ReconciliationController extends Controller
{
    public const STATUSES = ['pendentes' => 'Pendentes', 'conferidos' => 'Conferidos', 'todos' => 'Todos'];

    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): View
    {
        Gate::authorize('reconcile', FinancialEntry::class);

        $today = ClinicTime::today();
        $filters = $request->validate([
            'de' => ['nullable', 'date_format:Y-m-d', 'after:2000-01-01', 'before:2100-01-01'],
            'ate' => ['nullable', 'date_format:Y-m-d', 'after:2000-01-01', 'before:2100-01-01'],
            'forma' => ['nullable', Rule::enum(PaymentMethod::class)->except([PaymentMethod::Cash])],
            'situacao' => ['nullable', Rule::in(array_keys(self::STATUSES))],
        ]);
        $month = CarbonImmutable::createFromFormat('!Y-m-d', $today);
        $start = $filters['de'] ?? $month->startOfMonth()->format('Y-m-d');
        $end = $filters['ate'] ?? $today;
        $status = $filters['situacao'] ?? 'pendentes';
        $method = $filters['forma'] ?? null;

        $this->audit->log(AuditAction::FinanceViewed, null, ['view' => 'reconciliation', 'from' => $start, 'to' => $end]);

        $received = fn () => FinancialInstallment::query()
            ->whereNotNull('paid_at')
            ->whereBetween('paid_at', [$start, $end])
            ->whereHas('entry', fn ($entry) => $entry
                ->where('type', FinancialEntryType::Income->value)
                ->where('payment_method', '!=', PaymentMethod::Cash->value)
                ->when($method, fn ($query, string $value) => $query->where('payment_method', $value)));

        $summary = $received()->toBase()
            ->selectRaw('coalesce(sum(amount), 0) as total')
            ->selectRaw('coalesce(sum(case when reconciled_on is not null then amount end), 0) as reconciled')
            ->selectRaw('coalesce(sum(fee), 0) as fees')
            ->first();

        $installments = $received()
            ->when($status === 'pendentes', fn ($query) => $query->whereNull('reconciled_on'))
            ->when($status === 'conferidos', fn ($query) => $query->whereNotNull('reconciled_on'))
            ->with(['entry' => fn ($query) => $query
                ->select(['id', 'description', 'payment_method', 'patient_id'])
                ->withCount('installments')
                ->with(['patient' => fn ($patient) => $patient->withTrashed()->select(['id', 'name'])]),
            ])
            ->orderBy('paid_at')
            ->orderBy('id')
            ->paginate(ReconcileRequest::MAX_ROWS)
            ->withQueryString();

        return view('finance.reconciliation', [
            'installments' => $installments,
            'summary' => $summary,
            'filters' => ['de' => $start, 'ate' => $end, 'forma' => $method, 'situacao' => $status],
            'methods' => collect(PaymentMethod::options())->except(PaymentMethod::Cash->value)->all(),
            'statuses' => self::STATUSES,
            'today' => $today,
        ]);
    }

    public function store(ReconcileRequest $request): RedirectResponse
    {
        $fees = $request->fees();
        $day = $request->validated('reconciled_on');

        DB::transaction(function () use ($fees, $day, $request): void {
            $installments = ReconcileRequest::eligible()->whereKey(array_keys($fees))->lockForUpdate()->with('entry:id,patient_id')->get();

            foreach ($installments as $installment) {
                $installment->update(['reconciled_on' => $day, 'fee' => $fees[$installment->id], 'reconciled_by' => $request->user()->getKey()]);

                $this->audit->log(AuditAction::InstallmentReconciled, $installment, array_filter([
                    'financial_entry_id' => $installment->financial_entry_id,
                    'patient_id' => $installment->entry->patient_id,
                    'amount' => $installment->amount,
                    'fee' => $installment->fee,
                    'reconciled_on' => $day,
                ], fn ($value) => $value !== null));
            }
        });

        $count = count($fees);

        return redirect()->back(fallback: route('finance.reconciliation'))
            ->with('success', $count === 1 ? 'Recebimento conciliado.' : "{$count} recebimentos conciliados.");
    }

    public function destroy(FinancialInstallment $installment): RedirectResponse
    {
        Gate::authorize('reconcile', FinancialEntry::class);

        abort_unless($installment->isReconciled(), 404);

        DB::transaction(function () use ($installment): void {
            $this->audit->log(AuditAction::InstallmentUnreconciled, $installment, array_filter([
                'financial_entry_id' => $installment->financial_entry_id,
                'amount' => $installment->amount,
                'fee' => $installment->fee,
                'reconciled_on' => $installment->reconciled_on->format('Y-m-d'),
            ], fn ($value) => $value !== null));

            $installment->update(['reconciled_on' => null, 'fee' => null, 'reconciled_by' => null]);
        });

        return redirect()->back(fallback: route('finance.reconciliation'))->with('success', 'Conciliação desfeita.');
    }
}
