<?php

namespace App\Http\Controllers;

use App\Enums\AuditAction;
use App\Enums\PaymentMethod;
use App\Http\Requests\AdjustChargeRequest;
use App\Models\FinancialEntry;
use App\Services\AuditLogger;
use App\Services\FinancialRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cobrança de um atendimento registrada com a forma ou o parcelamento
 * errados: quem registra o atendimento nem sempre sabe como o paciente vai
 * pagar. O ajuste refaz as parcelas sem tocar no registro clínico e só
 * enquanto nada foi recebido.
 */
class TreatmentChargeController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly FinancialRecorder $finance,
    ) {}

    public function update(AdjustChargeRequest $request, FinancialEntry $entry): RedirectResponse
    {
        DB::transaction(function () use ($request, $entry): void {
            // Trava o lançamento: um recebimento simultâneo não pode entrar no meio.
            $locked = FinancialEntry::query()->lockForUpdate()->findOrFail($entry->getKey());

            if ($locked->installments()->whereNotNull('paid_at')->exists()) {
                throw ValidationException::withMessages(['installments' => 'A cobrança já tem pagamento registrado e não pode mais ser ajustada.']);
            }

            $before = [
                'payment_method' => $locked->payment_method->value,
                'installments' => $locked->installments()->count(),
                'first_due_date' => $locked->installments()->orderBy('number')->first()?->due_date->format('Y-m-d'),
            ];
            $this->finance->reschedule($locked, PaymentMethod::from($request->validated('payment_method')), (int) $request->validated('installments'), $request->validated('first_due_date'));

            $this->audit->log(AuditAction::FinancialEntryUpdated, $locked, array_filter([
                'fields' => ['payment_method', 'installments', 'first_due_date'],
                'from' => $before,
                'to' => ['payment_method' => $request->validated('payment_method'), 'installments' => (int) $request->validated('installments'), 'first_due_date' => $request->validated('first_due_date')],
                'treatment_id' => $locked->treatment_id,
                'patient_id' => $locked->patient_id,
            ], fn ($value) => $value !== null));
        });

        return redirect()->back(fallback: route('finance.index'))->with('success', 'Cobrança ajustada. As parcelas foram refeitas.');
    }
}
