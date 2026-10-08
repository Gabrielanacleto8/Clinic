<?php

namespace App\Http\Controllers;

use App\Enums\AuditAction;
use App\Models\FinancialEntry;
use App\Models\FinancialInstallment;
use App\Services\AuditLogger;
use App\Support\ClinicTime;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class InstallmentPaymentController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Registra o pagamento de uma parcela em aberto.
     */
    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('settle', FinancialEntry::class);

        $validated = $request->validate([
            // Só parcelas ainda em aberto: pagar duas vezes não é possível.
            'installment_id' => ['required', 'integer', Rule::exists(FinancialInstallment::class, 'id')->whereNull('paid_at')],
            'paid_at' => ['required', 'date_format:Y-m-d', 'after:2000-01-01', 'before_or_equal:'.ClinicTime::today()],
        ], [
            'installment_id.exists' => 'Esta parcela não está mais em aberto.',
            'paid_at.before_or_equal' => 'A data do pagamento não pode estar no futuro.',
        ], ['installment_id' => 'parcela', 'paid_at' => 'data do pagamento']);

        DB::transaction(function () use ($validated): void {
            $installment = FinancialInstallment::query()->whereKey($validated['installment_id'])->lockForUpdate()->firstOrFail();
            $installment->update(['paid_at' => $validated['paid_at']]);

            $this->audit->log(AuditAction::InstallmentPaid, $installment, ['financial_entry_id' => $installment->financial_entry_id]);
        });

        return redirect()->back(fallback: route('finance.index'))->with('success', 'Pagamento registrado.');
    }

    /**
     * Desfaz um pagamento registrado por engano.
     */
    public function destroy(FinancialInstallment $installment): RedirectResponse
    {
        Gate::authorize('settle', FinancialEntry::class);

        abort_unless($installment->isPaid(), 404);

        DB::transaction(function () use ($installment): void {
            $installment->update(['paid_at' => null]);

            $this->audit->log(AuditAction::InstallmentReopened, $installment, ['financial_entry_id' => $installment->financial_entry_id]);
        });

        return redirect()->back(fallback: route('finance.index'))->with('success', 'Pagamento desfeito. A parcela voltou a ficar em aberto.');
    }
}
