<?php

namespace App\Services;

use App\Enums\AppointmentStatus;
use App\Enums\AuditAction;
use App\Models\Appointment;
use App\Models\FinancialEntry;
use App\Models\Treatment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Anula um atendimento registrado com erro (paciente, lote, quantidade ou
 * valor errados). Em uma transação: devolve ao estoque o que ele baixou,
 * exclui a cobrança que ele gerou, reabre o agendamento que ele encerrou e o
 * marca como anulado, com quem, quando e por quê. O registro em si não muda
 * nem some: continua no prontuário para a história ficar completa, e o
 * registro certo é feito de novo.
 */
class TreatmentVoider
{
    public const PAID_MESSAGE = 'A cobrança deste atendimento tem pagamento registrado. Desfaça o pagamento no financeiro antes de anular.';

    public function __construct(
        private readonly StockLedger $stock,
        private readonly AppointmentScheduler $scheduler,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @throws ValidationException
     */
    public function void(Treatment $treatment, User $user, string $reason): Treatment
    {
        return DB::transaction(function () use ($treatment, $user, $reason): Treatment {
            // Trava o registro: dois pedidos simultâneos não anulam duas vezes.
            $locked = Treatment::query()->whereKey($treatment->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->isVoided()) {
                throw ValidationException::withMessages(['reason' => 'Este atendimento já foi anulado.']);
            }

            $entryId = $this->removeCharge($locked);
            // O motivo digitado pode citar dado clínico: no estoque, visível a
            // quem não vê o prontuário, vai só uma referência ao atendimento.
            $reversed = count($this->stock->reverseTreatment($locked, 'Estorno pela anulação do atendimento nº '.$locked->getKey(), $user));
            $appointmentId = $this->reopenAppointment($locked);

            $locked->voided_at = now();
            $locked->voided_by = $user->getKey();
            $locked->void_reason = $reason;
            $locked->save();

            // O motivo fica só no registro (criptografado), nunca na auditoria.
            $this->audit->log(AuditAction::TreatmentVoided, $locked, array_filter([
                'patient_id' => $locked->patient_id,
                'stock_movements_reversed' => $reversed,
                'financial_entry_id' => $entryId,
                'appointment_id' => $appointmentId,
            ], fn ($value) => $value !== null));

            return $locked;
        });
    }

    /**
     * Exclui a cobrança gerada pelo atendimento. Com pagamento registrado, a
     * anulação é recusada: desfazer o recebimento é decisão do financeiro.
     *
     * @throws ValidationException
     */
    private function removeCharge(Treatment $treatment): ?int
    {
        $entry = FinancialEntry::query()->where('treatment_id', $treatment->getKey())->lockForUpdate()->first();

        if ($entry === null) {
            return null;
        }

        if ($entry->installments()->whereNotNull('paid_at')->exists()) {
            throw ValidationException::withMessages(['reason' => self::PAID_MESSAGE]);
        }

        $this->audit->log(AuditAction::FinancialEntryDeleted, $entry, ['type' => $entry->type->value, 'patient_id' => $treatment->patient_id, 'treatment_id' => $treatment->getKey(), 'reason' => 'treatment_voided']);

        // As parcelas saem junto, pela chave estrangeira em cascata.
        $entry->delete();

        return $entry->getKey();
    }

    /**
     * Devolve o agendamento encerrado pelo atendimento à agenda, para receber
     * o registro certo. Se o horário já foi ocupado por outro agendamento,
     * ele fica cancelado.
     */
    private function reopenAppointment(Treatment $treatment): ?int
    {
        $appointment = Appointment::query()->where('treatment_id', $treatment->getKey())->lockForUpdate()->first();

        if ($appointment === null) {
            return null;
        }

        $appointment->treatment_id = null;
        $appointment->status = AppointmentStatus::Scheduled;

        try {
            $this->scheduler->save($appointment);
        } catch (ValidationException) {
            $appointment->status = AppointmentStatus::Cancelled;
            $this->scheduler->save($appointment);
        }

        $this->audit->log(AuditAction::AppointmentStatusChanged, $appointment, [
            'patient_id' => $appointment->patient_id,
            'from' => AppointmentStatus::Completed->value,
            'to' => $appointment->status->value,
            'reason' => 'treatment_voided',
        ]);

        return $appointment->getKey();
    }
}
