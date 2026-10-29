<?php

namespace App\Http\Controllers;

use App\Enums\AuditAction;
use App\Enums\PaymentMethod;
use App\Models\CommissionPayout;
use App\Models\CommissionRate;
use App\Models\FinancialEntry;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\CommissionLedger;
use App\Support\ClinicTime;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Aba Comissões: quanto cada profissional tem a receber e o detalhe por atendimento.
 */
class CommissionController extends Controller
{
    public function __construct(private readonly CommissionLedger $ledger, private readonly AuditLogger $audit) {}

    public function index(): View
    {
        Gate::authorize('viewAny', FinancialEntry::class);

        $today = ClinicTime::today();
        $summary = $this->ledger->summary($today)->keyBy('professional_id');
        $ids = $summary->keys()->merge(CommissionRate::query()->distinct()->pluck('user_id'))->unique();

        $this->audit->log(AuditAction::FinanceViewed, null, ['view' => 'commissions']);

        return view('finance.commissions', [
            'professionals' => User::query()->whereKey($ids)->orderBy('name')->get(['id', 'name', 'is_active']),
            'summary' => $summary,
            'defaults' => CommissionRate::query()->whereNull('procedure_id')->pluck('rate', 'user_id'),
            'exceptions' => CommissionRate::query()->whereNotNull('procedure_id')->selectRaw('user_id, count(*) as total')->groupBy('user_id')->pluck('total', 'user_id'),
        ]);
    }

    public function show(Request $request, User $professional): View
    {
        Gate::authorize('viewAny', FinancialEntry::class);

        $today = ClinicTime::today();
        $until = $request->validate(['ate' => ['nullable', 'date_format:Y-m-d', 'after:2000-01-01', 'before_or_equal:'.$today]])['ate'] ?? $today;

        $this->audit->log(AuditAction::FinanceViewed, null, ['view' => 'commission_detail', 'professional_id' => $professional->getKey(), 'until' => $until]);

        $rows = $this->ledger->rows($until, $professional->getKey());

        return view('finance.commission', [
            'professional' => $professional,
            'until' => $until,
            'today' => $today,
            // Só o que tem algo a mostrar: comissão ou repasse.
            'rows' => $rows->filter(fn (object $row) => $row->earned != 0.0 || $row->paid != 0.0 || $row->pending != 0.0)->values(),
            'payouts' => CommissionPayout::query()
                ->where('professional_id', $professional->getKey())
                ->with(['entry' => fn ($query) => $query->withSum('installments as total_amount', 'amount')->withExists(['installments as has_paid_installment' => fn ($paid) => $paid->whereNotNull('paid_at')])])
                ->latest('id')
                ->limit(12)
                ->get(),
            'paymentMethods' => PaymentMethod::options(),
        ]);
    }
}
