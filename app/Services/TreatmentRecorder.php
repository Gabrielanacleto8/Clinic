<?php

namespace App\Services;

use App\Enums\AppointmentStatus;
use App\Enums\AuditAction;
use App\Enums\FinancialCategory;
use App\Enums\PaymentMethod;
use App\Exceptions\InsufficientStockException;
use App\Models\Appointment;
use App\Models\Treatment;
use App\Models\User;
use App\Support\ClinicTime;
use App\Support\LocalizedDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Registra um atendimento. Em uma única transação: grava o registro e os
 * produtos usados, baixa o estoque, gera o lançamento financeiro e encerra o
 * agendamento de origem. Se qualquer passo falha, nada é gravado.
 */
class TreatmentRecorder
{
    public const APPOINTMENT_NOT_OPEN = 'Este agendamento não está mais em aberto. Confira a situação dele na agenda.';

    public function __construct(
        private readonly StockLedger $stock,
        private readonly FinancialRecorder $finance,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  Treatment  $treatment  Ainda não gravado, já com paciente, profissional, procedimento e autor.
     * @param  list<array{product_batch_id: int, quantity: string}>  $products
     * @param  array{amount: string, payment_method: PaymentMethod, installments: int, first_due_date: string, first_installment_paid: bool}|null  $payment  Nulo quando não há cobrança.
     * @param  int|null  $appointmentId  Agendamento que este atendimento encerra, se houver.
     *
     * @throws ValidationException
     */
    public function record(Treatment $treatment, array $products, ?array $payment, ?int $appointmentId, User $user): Treatment
    {
        return DB::transaction(function () use ($treatment, $products, $payment, $appointmentId, $user): Treatment {
            $treatment->save();
            $treatment->products()->createMany($products);
            $treatment->load(['products.batch.product', 'procedure:id,name', 'patient:id,name']);

            $this->withdrawProducts($treatment, $user);

            if ($payment !== null) {
                $this->finance->record(
                    category: FinancialCategory::Treatment,
                    description: 'Atendimento: '.$treatment->procedure->name,
                    total: $payment['amount'],
                    paymentMethod: $payment['payment_method'],
                    installments: $payment['installments'],
                    firstDueDate: $payment['first_due_date'],
                    firstInstallmentPaid: $payment['first_installment_paid'],
                    createdBy: $user,
                    // A receita é do dia em que o atendimento foi feito, mesmo registrado depois.
                    issuedOn: ClinicTime::format($treatment->performed_at, 'Y-m-d'),
                    patient: $treatment->patient,
                    treatment: $treatment,
                );
            }

            if ($appointmentId !== null) {
                $this->completeAppointment($appointmentId, $treatment);
            }

            $this->audit->log(AuditAction::TreatmentCreated, $treatment, [
                'patient_id' => $treatment->patient_id,
                'products' => $treatment->products->count(),
                'charged' => $payment !== null,
            ]);

            return $treatment;
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

            // Produto vencido ou retirado de uso não é aplicado em paciente.
            if ($batch->isExpired()) {
                throw ValidationException::withMessages(['products' => "O lote de {$name} está vencido. Escolha outro lote."]);
            }

            if (! $batch->product->is_active) {
                throw ValidationException::withMessages(['products' => "O produto {$batch->product->name} está inativo. Retire-o do atendimento."]);
            }

            try {
                $this->stock->consume($batch, $used->quantity, $treatment, $user);
            } catch (InsufficientStockException $exception) {
                throw ValidationException::withMessages([
                    'products' => "Saldo insuficiente de {$name}. Disponível: ".LocalizedDecimal::quantity($exception->available)
                        .', necessário: '.LocalizedDecimal::quantity($used->quantity).'.',
                ]);
            }
        }
    }

    /**
     * Liga o agendamento ao atendimento e o marca como atendido. A trava
     * impede que dois envios simultâneos registrem dois atendimentos para o
     * mesmo agendamento.
     *
     * @throws ValidationException
     */
    private function completeAppointment(int $appointmentId, Treatment $treatment): void
    {
        $appointment = Appointment::query()->whereKey($appointmentId)->lockForUpdate()->first();

        if (! $appointment?->isOpenForTreatment() || $appointment->patient_id !== $treatment->patient_id) {
            throw ValidationException::withMessages(['appointment_id' => self::APPOINTMENT_NOT_OPEN]);
        }

        $from = $appointment->status;
        $appointment->treatment_id = $treatment->getKey();
        $appointment->status = AppointmentStatus::Completed;
        $appointment->save();

        $this->audit->log(AuditAction::AppointmentStatusChanged, $appointment, [
            'patient_id' => $appointment->patient_id,
            'from' => $from->value,
            'to' => AppointmentStatus::Completed->value,
        ]);
    }
}
