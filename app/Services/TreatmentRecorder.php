<?php

namespace App\Services;

use App\Enums\AppointmentStatus;
use App\Enums\AuditAction;
use App\Enums\FinancialCategory;
use App\Enums\PaymentMethod;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\UnusableStockException;
use App\Models\Appointment;
use App\Models\ProductBatch;
use App\Models\StockUnit;
use App\Models\Treatment;
use App\Models\TreatmentPlanItem;
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

    public const PLAN_SESSION_UNAVAILABLE = 'Esta sessão de pacote não está mais disponível para o paciente. Recarregue a página.';

    public function __construct(
        private readonly StockLedger $stock,
        private readonly FinancialRecorder $finance,
        private readonly AuditLogger $audit,
        private readonly CommissionRules $commissions,
    ) {}

    /**
     * @param  Treatment  $treatment  Ainda não gravado, já com paciente, profissional, procedimento e autor.
     * @param  list<array{product_batch_id: int, stock_unit_id?: int|null, quantity: string}>  $products  No fracionado, sem unidade abre uma nova do lote.
     * @param  array{amount: string, discount?: string, payment_method: PaymentMethod, installments: int, first_due_date: string, first_installment_paid: bool}|null  $payment  Nulo quando não há cobrança.
     * @param  int|null  $appointmentId  Agendamento que este atendimento encerra, se houver.
     * @param  int|null  $planItemId  Item de pacote cuja sessão este atendimento usa; sem cobrança própria.
     *
     * @throws ValidationException
     */
    public function record(Treatment $treatment, array $products, ?array $payment, ?int $appointmentId, User $user, ?int $planItemId = null): Treatment
    {
        return DB::transaction(function () use ($treatment, $products, $payment, $appointmentId, $user, $planItemId): Treatment {
            if ($planItemId !== null) {
                $this->claimPlanSession($planItemId, $treatment);
                $payment = null;
            }

            // A comissão do atendimento é a regra que vale hoje: mudar a regra depois não altera o passado.
            $treatment->commission_rate = $this->commissions->rateFor($treatment->professional_id, $treatment->procedure_id);
            $treatment->save();

            $this->withdrawProducts($treatment, $products, $user);

            $treatment->load(['products.batch.product', 'procedure:id,name', 'patient:id,name']);

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
                    discount: $payment['discount'] ?? '0',
                );
            }

            if ($appointmentId !== null) {
                $this->completeAppointment($appointmentId, $treatment);
            }

            $this->audit->log(AuditAction::TreatmentCreated, $treatment, [
                'patient_id' => $treatment->patient_id,
                'products' => $treatment->products->count(),
                'charged' => $payment !== null,
                ...($planItemId === null ? [] : ['plan_item_id' => $planItemId]),
            ]);

            return $treatment;
        });
    }

    /**
     * Baixa cada produto usado e grava o que foi aplicado no paciente. No
     * fracionado, a baixa sai da unidade física informada ou de uma unidade
     * aberta agora; se a sobra não pode ir para outro paciente, ela é
     * descartada ao fim do atendimento.
     *
     * @param  list<array{product_batch_id: int, stock_unit_id?: int|null, quantity: string}>  $products
     *
     * @throws ValidationException
     */
    private function withdrawProducts(Treatment $treatment, array $products, User $user): void
    {
        $batches = ProductBatch::query()->with('product')->whereKey(array_column($products, 'product_batch_id'))->get()->keyBy('id');
        $unitIds = array_filter(array_column($products, 'stock_unit_id'));
        $units = $unitIds === [] ? collect() : StockUnit::query()->whereKey($unitIds)->get()->keyBy('id');

        foreach ($products as $row) {
            $batch = $batches->get($row['product_batch_id']) ?? throw ValidationException::withMessages(['products' => 'Um dos lotes informados não existe mais. Recarregue a página.']);
            $product = $batch->product;
            $name = $product->name.', lote '.$batch->batch_code;
            $unit = isset($row['stock_unit_id']) ? $units->get($row['stock_unit_id']) : null;
            $openedNow = false;

            // Produto vencido ou retirado de uso não é aplicado em paciente.
            if ($batch->isExpired()) {
                throw ValidationException::withMessages(['products' => "O lote de {$name} está vencido. Escolha outro lote."]);
            }

            if (! $product->is_active) {
                throw ValidationException::withMessages(['products' => "O produto {$product->name} está inativo. Retire-o do atendimento."]);
            }

            if (isset($row['stock_unit_id']) && ($unit === null || ! $product->isFractioned() || $unit->product_batch_id !== $batch->getKey())) {
                throw ValidationException::withMessages(['products' => "A unidade informada para {$name} não é deste lote."]);
            }

            try {
                if ($product->isFractioned()) {
                    if ($unit === null) {
                        $unit = $this->stock->openUnit($batch, $user);
                        $openedNow = true;
                    }
                    $unit->setRelation('batch', $batch);
                    $this->stock->consumeFromUnit($unit, $row['quantity'], $treatment, $user);

                    if (! $product->multi_patient_use) {
                        $this->stock->discard($unit, 'Sobra de unidade de uso em um só paciente, descartada ao fim do atendimento.', $user, $treatment);
                    }
                } else {
                    $this->stock->consume($batch, $row['quantity'], $treatment, $user);
                }
            } catch (UnusableStockException $exception) {
                throw ValidationException::withMessages(['products' => $exception->getMessage()]);
            } catch (InsufficientStockException $exception) {
                // A unidade aberta agora é desfeita junto com o resto: citar o número dela confundiria.
                if ($openedNow) {
                    throw ValidationException::withMessages([
                        'products' => "A quantidade de {$name} passa do conteúdo de uma unidade ("
                            .LocalizedDecimal::quantity($product->unit_content).' '.$product->unit->symbol()
                            .'). Use uma linha para cada unidade aberta.',
                    ]);
                }

                $where = $exception->unit ? ' na '.mb_strtolower($exception->unit->label()) : '';

                throw ValidationException::withMessages([
                    'products' => "Saldo insuficiente de {$name}{$where}. Disponível: ".LocalizedDecimal::quantity($exception->available)
                        .', necessário: '.LocalizedDecimal::quantity($row['quantity']).'.',
                ]);
            }

            $treatment->products()->create([
                'product_batch_id' => $batch->getKey(),
                'stock_unit_id' => $unit?->getKey(),
                'quantity' => $row['quantity'],
            ]);
        }
    }

    /**
     * Reserva a sessão do pacote para este atendimento. A trava no item impede
     * que dois registros simultâneos usem a última sessão duas vezes.
     *
     * @throws ValidationException
     */
    private function claimPlanSession(int $planItemId, Treatment $treatment): void
    {
        TreatmentPlanItem::query()->whereKey($planItemId)->lockForUpdate()->first(['id']);

        $item = TreatmentPlanItem::query()
            ->availableFor($treatment->patient_id)
            ->whereKey($planItemId)
            ->first(['id', 'procedure_id', 'sessions']);

        if ($item === null || $item->procedure_id !== $treatment->procedure_id) {
            throw ValidationException::withMessages(['treatment_plan_item_id' => self::PLAN_SESSION_UNAVAILABLE]);
        }

        $treatment->treatment_plan_item_id = $item->getKey();
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
