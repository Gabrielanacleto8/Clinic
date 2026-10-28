<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Enums\FinancialCategory;
use App\Enums\PaymentMethod;
use App\Enums\TreatmentPlanStatus;
use App\Models\TreatmentPlan;
use App\Models\TreatmentPlanItem;
use App\Models\User;
use App\Support\ClinicTime;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Muda a situação de um orçamento. Aprovar, na mesma transação, gera a
 * cobrança do pacote inteiro; as sessões, depois, são registradas sem cobrança.
 */
class TreatmentPlanApproval
{
    public function __construct(
        private readonly FinancialRecorder $finance,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{payment_method: PaymentMethod, installments: int, first_due_date: string, first_installment_paid: bool}  $payment
     *
     * @throws ValidationException
     */
    public function approve(TreatmentPlan $plan, array $payment, User $user): TreatmentPlan
    {
        return DB::transaction(function () use ($plan, $payment, $user): TreatmentPlan {
            $locked = $this->lockOpen($plan);

            if ($locked->isExpired()) {
                throw ValidationException::withMessages(['payment_method' => 'A validade deste orçamento passou. Edite a validade antes de aprovar.']);
            }

            $locked->load(['items.procedure:id,name', 'patient']);
            $total = $locked->total();

            $locked->status = TreatmentPlanStatus::Approved;
            $locked->approved_at = now();
            $locked->approved_by = $user->getKey();
            $locked->save();

            // Pacote sem valor (cortesia) não gera lançamento.
            if ((float) $total > 0) {
                $this->finance->record(
                    category: FinancialCategory::TreatmentPlan,
                    description: 'Pacote nº '.$locked->getKey().': '.$locked->items->map(fn (TreatmentPlanItem $item) => $item->procedure->name.' ('.$item->sessions.')')->implode(', '),
                    total: $total,
                    paymentMethod: $payment['payment_method'],
                    installments: $payment['installments'],
                    firstDueDate: $payment['first_due_date'],
                    firstInstallmentPaid: $payment['first_installment_paid'],
                    createdBy: $user,
                    issuedOn: ClinicTime::today(),
                    patient: $locked->patient,
                    plan: $locked,
                );
            }

            $this->audit->log(AuditAction::TreatmentPlanApproved, $locked, ['patient_id' => $locked->patient_id, 'total' => $total, 'charged' => (float) $total > 0]);

            return $locked;
        });
    }

    /**
     * @throws ValidationException
     */
    public function decline(TreatmentPlan $plan, User $user): void
    {
        DB::transaction(function () use ($plan, $user): void {
            $locked = $this->lockOpen($plan);
            $this->finish($locked, TreatmentPlanStatus::Declined, $user, AuditAction::TreatmentPlanDeclined);
        });
    }

    /**
     * @throws ValidationException
     */
    public function close(TreatmentPlan $plan, User $user): void
    {
        DB::transaction(function () use ($plan, $user): void {
            $locked = TreatmentPlan::query()->lockForUpdate()->findOrFail($plan->getKey());

            if (! $locked->isApproved()) {
                throw ValidationException::withMessages(['plan' => 'Só um pacote aprovado pode ser encerrado.']);
            }

            $this->finish($locked, TreatmentPlanStatus::Closed, $user, AuditAction::TreatmentPlanClosed);
        });
    }

    /**
     * @throws ValidationException
     */
    private function lockOpen(TreatmentPlan $plan): TreatmentPlan
    {
        $locked = TreatmentPlan::query()->lockForUpdate()->findOrFail($plan->getKey());

        if (! $locked->isOpen()) {
            throw ValidationException::withMessages(['payment_method' => 'Este orçamento não está mais em aberto.']);
        }

        return $locked;
    }

    private function finish(TreatmentPlan $plan, TreatmentPlanStatus $status, User $user, AuditAction $action): void
    {
        $plan->status = $status;
        $plan->closed_at = now();
        $plan->closed_by = $user->getKey();
        $plan->save();

        $this->audit->log($action, $plan, ['patient_id' => $plan->patient_id]);
    }
}
