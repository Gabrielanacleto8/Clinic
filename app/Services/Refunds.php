<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Enums\FinancialCategory;
use App\Enums\PaymentMethod;
use App\Enums\RefundReason;
use App\Models\FinancialEntry;
use App\Models\User;
use App\Support\LocalizedDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Estorno: devolução ao paciente de parte ou de todo o valor que ele já
 * pagou numa cobrança. Vira uma saída paga, ligada à cobrança, que entra no
 * caixa do dia e desconta a base da comissão.
 */
class Refunds
{
    public function __construct(
        private readonly FinancialRecorder $finance,
        private readonly CashRegister $cash,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Quanto ainda pode ser devolvido: o pago menos o já estornado.
     */
    public function refundable(FinancialEntry $entry): float
    {
        $paid = (float) $entry->installments()->whereNotNull('paid_at')->sum('amount');
        $refunded = (float) DB::table('financial_installments')
            ->whereIn('financial_entry_id', $entry->refunds()->select('id'))
            ->sum('amount');

        return round($paid - $refunded, 2);
    }

    /**
     * @throws ValidationException
     */
    public function refund(FinancialEntry $entry, string $amount, PaymentMethod $method, string $date, RefundReason $reason, User $user): FinancialEntry
    {
        return DB::transaction(function () use ($entry, $amount, $method, $date, $reason, $user): FinancialEntry {
            // A trava na cobrança serializa estornos e o desfazer de pagamentos dela.
            $locked = FinancialEntry::query()->lockForUpdate()->findOrFail($entry->getKey());

            if (! $locked->isPatientCharge()) {
                throw ValidationException::withMessages(['entry_id' => 'Só cobrança de paciente pode ser estornada.']);
            }

            $refundable = $this->refundable($locked);

            if ((float) $amount > $refundable) {
                throw ValidationException::withMessages(['amount' => $refundable > 0
                    ? 'O estorno passa do que o paciente pagou e ainda não foi devolvido ('.LocalizedDecimal::money($refundable).').'
                    : 'Não há valor pago a devolver nesta cobrança.']);
            }

            $refund = $this->finance->record(
                category: FinancialCategory::Refund,
                description: 'Estorno ('.mb_strtolower($reason->label()).'): '.$locked->description,
                total: $amount,
                paymentMethod: $method,
                installments: 1,
                firstDueDate: $date,
                firstInstallmentPaid: true,
                createdBy: $user,
                issuedOn: $date,
                patient: $locked->patient()->withTrashed()->first(),
                links: ['refund_of_id' => $locked->getKey()],
            );

            $this->audit->log(AuditAction::RefundRecorded, $refund, [
                'refund_of_id' => $locked->getKey(),
                'patient_id' => $locked->patient_id,
                'amount' => $amount,
                'payment_method' => $method->value,
                'reason' => $reason->value,
            ]);

            return $refund;
        });
    }

    /**
     * Desfaz um estorno lançado por engano, enquanto o caixa do dia dele está aberto.
     *
     * @throws ValidationException
     */
    public function undo(FinancialEntry $refund): void
    {
        DB::transaction(function () use ($refund): void {
            FinancialEntry::query()->lockForUpdate()->findOrFail($refund->refund_of_id);
            $installment = $refund->installments()->first();

            if ($installment?->paid_at) {
                $this->cash->assertOpen($installment->paid_at->format('Y-m-d'), 'refund');
            }

            $this->audit->log(AuditAction::RefundUndone, $refund, [
                'refund_of_id' => $refund->refund_of_id,
                'patient_id' => $refund->patient_id,
                'amount' => $refund->total(),
            ]);

            $refund->delete();
        });
    }
}
