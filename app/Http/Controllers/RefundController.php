<?php

namespace App\Http\Controllers;

use App\Enums\PaymentMethod;
use App\Enums\RefundReason;
use App\Http\Requests\RefundRequest;
use App\Models\FinancialEntry;
use App\Services\Refunds;
use App\Support\LocalizedDecimal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Estornos ao paciente: registrar e desfazer o lançado por engano.
 */
class RefundController extends Controller
{
    public function __construct(private readonly Refunds $refunds) {}

    public function store(RefundRequest $request): RedirectResponse
    {
        $refund = $this->refunds->refund(
            $request->entry(),
            (string) $request->validated('amount'),
            PaymentMethod::from($request->validated('payment_method')),
            $request->validated('refunded_on'),
            RefundReason::from($request->validated('reason')),
            $request->user(),
        );

        return redirect()->back(fallback: route('finance.index'))
            ->with('success', 'Estorno de '.LocalizedDecimal::money($refund->total()).' registrado. A saída já está no caixa do dia.');
    }

    public function destroy(FinancialEntry $refund): RedirectResponse
    {
        Gate::authorize('manage', FinancialEntry::class);

        abort_if($refund->refund_of_id === null, 404);

        $this->refunds->undo($refund);

        return redirect()->back(fallback: route('finance.index'))->with('success', 'Estorno desfeito.');
    }
}
