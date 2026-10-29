<?php

namespace App\Http\Controllers;

use App\Enums\AuditAction;
use App\Http\Requests\CloseCashRequest;
use App\Models\CashClosing;
use App\Models\FinancialEntry;
use App\Models\FinancialInstallment;
use App\Services\AuditLogger;
use App\Services\CashRegister;
use App\Support\ClinicTime;
use App\Support\LocalizedDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Aba Caixa do dia: o que entrou e saiu em cada forma de pagamento, a
 * conferência e o fechamento.
 */
class CashClosingController extends Controller
{
    /** Dias para trás em que se procura movimento sem fechamento. */
    private const PENDING_DAYS = 30;

    public function __construct(private readonly CashRegister $cash, private readonly AuditLogger $audit) {}

    public function show(Request $request): View
    {
        Gate::authorize('viewCash', FinancialEntry::class);

        $today = ClinicTime::today();
        $day = $request->validate(['dia' => ['nullable', 'date_format:Y-m-d', 'after:2000-01-01', 'before_or_equal:'.$today]])['dia'] ?? $today;

        // A lista de pagamentos do dia tem nome de paciente: leitura auditada.
        $this->audit->log(AuditAction::FinanceViewed, null, ['view' => 'cash', 'day' => $day]);

        $movement = $this->cash->movement($day);
        $closing = CashClosing::query()->with(['lines', 'closer:id,name'])->where('day', $day)->first();

        return view('finance.cash', [
            'day' => $day,
            'today' => $today,
            'movement' => $movement,
            'methods' => $this->cash->methodsToCount($movement),
            'closing' => $closing,
            'payments' => FinancialInstallment::query()
                ->where('paid_at', $day)
                ->with(['entry' => fn ($query) => $query
                    ->select(['id', 'type', 'category', 'description', 'payment_method', 'patient_id'])
                    ->with(['patient' => fn ($patient) => $patient->withTrashed()->select(['id', 'name'])]),
                ])
                ->orderBy('id')
                ->get(),
            'recent' => CashClosing::query()->with('lines')->where('day', '<', $day)->latest('day')->limit(7)->get(),
            'pendingDays' => $this->pendingDays($today),
        ]);
    }

    public function store(CloseCashRequest $request): RedirectResponse
    {
        $day = $request->validated('day');
        $closing = $this->cash->close($day, $request->counted(), $request->validated('notes'), $request->user());
        $difference = $closing->load('lines')->difference();

        $message = 'Caixa de '.$closing->day->format('d/m/Y').' fechado.';

        if ($difference != 0.0) {
            $message .= ($difference < 0 ? ' Falta de ' : ' Sobra de ').LocalizedDecimal::money(abs($difference)).' registrada.';
        }

        return redirect()->route('finance.cash.show', ['dia' => $day])->with('success', $message);
    }

    public function destroy(CashClosing $closing): RedirectResponse
    {
        Gate::authorize('reopenCash', FinancialEntry::class);

        $day = $closing->day->format('Y-m-d');
        $this->cash->reopen($closing);

        return redirect()->route('finance.cash.show', ['dia' => $day])
            ->with('success', 'Caixa de '.$closing->day->format('d/m/Y').' reaberto. Os pagamentos desse dia voltam a poder ser alterados.');
    }

    /**
     * Dias recentes, antes de hoje, com pagamento e sem fechamento.
     *
     * @return list<string>
     */
    private function pendingDays(string $today): array
    {
        $from = CarbonImmutable::createFromFormat('!Y-m-d', $today)->subDays(self::PENDING_DAYS)->format('Y-m-d');

        return DB::table('financial_installments')
            ->whereBetween('paid_at', [$from, $today])
            ->where('paid_at', '<', $today)
            ->whereNotIn('paid_at', CashClosing::query()->select('day'))
            ->distinct()
            ->orderByDesc('paid_at')
            ->pluck('paid_at')
            ->map(fn ($date) => substr((string) $date, 0, 10))
            ->all();
    }
}
