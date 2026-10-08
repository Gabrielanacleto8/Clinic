<?php

namespace App\Services;

use App\Enums\AppointmentStatus;
use App\Enums\AuditAction;
use App\Enums\FinancialCategory;
use App\Enums\PaymentMethod;
use App\Enums\TreatmentStatus;
use App\Exceptions\InsufficientStockException;
use App\Models\Appointment;
use App\Models\Treatment;
use App\Models\User;
use App\Support\LocalizedDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Finaliza um atendimento. Em uma única transação: baixa os produtos do
 * estoque, gera o lançamento financeiro, encerra o agendamento de origem e
 * marca o atendimento como histórico permanente. Se qualquer passo falha,
 * nada é gravado.
 */
class TreatmentFinalizer
{
    public function __construct(
        private readonly StockLedger $stock,
        private readonly FinancialRecorder $finance,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{amount: string, payment_method: PaymentMethod, installments: int, first_due_date: string, first_installment_paid: bool}|null  $payment  Nulo quando não há cobrança.
     *
     * @throws ValidationException
     */
    public function finalize(Treatment $treatment, User $user, ?array $payment): Treatment
    {
        return DB::transaction(function () use ($treatment, $user, $payment): Treatment {
            // A trava impede que dois envios finalizem o mesmo atendimento e
            // baixem o estoque em dobro.
            $locked = Treatment::query()->whereKey($treatment->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->isDraft()) {
                throw ValidationException::withMessages(['treatment' => 'Este atendimento já foi finalizado.']);
            }

            $locked->load(['products.batch.product', 'procedure:id,name', 'patient:id,name']);

            $this->withdrawProducts($locked, $user);

            if ($payment !== null) {
                $this->finance->record(
                    category: FinancialCategory::Treatment,
                    description: 'Atendimento: '.$locked->procedure->name,
                    total: $payment['amount'],
                    paymentMethod: $payment['payment_method'],
                    installments: $payment['installments'],
                    firstDueDate: $payment['first_due_date'],
                    firstInstallmentPaid: $payment['first_installment_paid'],
                    createdBy: $user,
                    patient: $locked->patient,
                    treatment: $locked,
                );
            }

            $locked->status = TreatmentStatus::Finalized;
            $locked->finalized_at = now();
            $locked->save();

            $this->completeAppointment($locked);

            $this->audit->log(AuditAction::TreatmentFinalized, $locked, [
                'patient_id' => $locked->patient_id,
                'products' => $locked->products->count(),
                'charged' => $payment !== null,
            ]);

            return $locked;
        });
    }

    /**
     * @throws ValidationException
     */
    private function withdrawProducts(Treatment $treatment, User $user): void
    {
        foreach ($treatment->products as $used) {
            $batch = $used->batch;
            $name = $batch->product->name.', lote '.$batch->batch_code;

            // Produto vencido não é aplicado em paciente.
            if ($batch->isExpired()) {
                throw ValidationException::withMessages(['products' => "O lote de {$name} está vencido. Troque o lote antes de finalizar."]);
            }

            try {
                $this->stock->withdraw($batch, $used->quantity, null, $user, $treatment);
            } catch (InsufficientStockException $exception) {
                throw ValidationException::withMessages([
                    'products' => "Saldo insuficiente de {$name}. Disponível: ".LocalizedDecimal::quantity($exception->available)
                        .', necessário: '.LocalizedDecimal::quantity($used->quantity).'.',
                ]);
            }
        }
    }

    private function completeAppointment(Treatment $treatment): void
    {
        $appointment = Appointment::query()->where('treatment_id', $treatment->getKey())->lockForUpdate()->first();

        if ($appointment === null || ! $appointment->isOpen()) {
            return;
        }

        $from = $appointment->status;
        $appointment->status = AppointmentStatus::Completed;
        $appointment->save();

        $this->audit->log(AuditAction::AppointmentStatusChanged, $appointment, [
            'patient_id' => $appointment->patient_id,
            'from' => $from->value,
            'to' => AppointmentStatus::Completed->value,
        ]);
    }
}
