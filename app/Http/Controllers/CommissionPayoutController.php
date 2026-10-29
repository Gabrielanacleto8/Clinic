<?php

namespace App\Http\Controllers;

use App\Enums\PaymentMethod;
use App\Http\Requests\CommissionPayoutRequest;
use App\Models\CommissionPayout;
use App\Models\FinancialEntry;
use App\Models\User;
use App\Services\CommissionPayouts;
use App\Support\LocalizedDecimal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class CommissionPayoutController extends Controller
{
    public function __construct(private readonly CommissionPayouts $payouts) {}

    public function store(CommissionPayoutRequest $request, User $professional): RedirectResponse
    {
        $payout = $this->payouts->pay(
            $professional,
            $request->validated('until'),
            PaymentMethod::from($request->validated('payment_method')),
            (bool) $request->validated('paid_now'),
            $request->user(),
        );

        $total = $payout->load('entry')->entry?->total() ?? '0';

        return redirect()->route('finance.commissions.show', [$professional, 'ate' => $request->validated('until')])
            ->with('success', 'Repasse de '.LocalizedDecimal::money($total).' registrado e lançado como despesa.');
    }

    public function destroy(CommissionPayout $payout): RedirectResponse
    {
        Gate::authorize('manage', FinancialEntry::class);

        $professionalId = $payout->professional_id;
        $this->payouts->delete($payout);

        return redirect()->route('finance.commissions.show', $professionalId)->with('success', 'Repasse excluído. A comissão volta a ficar a repassar.');
    }
}
