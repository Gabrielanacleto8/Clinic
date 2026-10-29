<?php

namespace App\Services;

use App\Enums\FinancialCategory;
use App\Enums\PaymentMethod;
use App\Models\FinancialEntry;
use App\Models\Patient;
use App\Models\Treatment;
use App\Models\TreatmentPlan;
use App\Models\User;
use App\Support\ClinicTime;
use App\Support\InstallmentPlan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Único ponto que cria lançamentos financeiros: grava o lançamento e as
 * parcelas na mesma transação. Usado pelo lançamento manual, pelo
 * registro de atendimentos e pela aprovação de orçamentos.
 */
class FinancialRecorder
{
    public function __construct(private readonly CashRegister $cash) {}

    /**
     * @param  bool  $firstInstallmentPaid  A primeira parcela (ou o valor à vista) já foi paga na data de competência.
     *                                      Na receita no cartão de crédito, vale para todas as parcelas.
     * @param  string  $discount  Desconto concedido sobre o preço de tabela, em reais.
     * @param  array{refund_of_id?: int, recurring_expense_id?: int, commission_payout_id?: int}  $links
     *
     * @throws ValidationException Dia de competência com o caixa fechado, quando já pago.
     */
    public function record(
        FinancialCategory $category,
        string $description,
        string $total,
        PaymentMethod $paymentMethod,
        int $installments,
        string $firstDueDate,
        bool $firstInstallmentPaid,
        ?User $createdBy,
        ?string $issuedOn = null,
        ?Patient $patient = null,
        ?Treatment $treatment = null,
        ?TreatmentPlan $plan = null,
        string $discount = '0',
        array $links = [],
    ): FinancialEntry {
        return DB::transaction(function () use ($category, $description, $total, $paymentMethod, $installments, $firstDueDate, $firstInstallmentPaid, $createdBy, $issuedOn, $patient, $treatment, $plan, $discount, $links): FinancialEntry {
            $issuedOn ??= ClinicTime::today();

            // Já pago na data de competência: entra no caixa desse dia, que precisa estar aberto.
            if ($firstInstallmentPaid) {
                $this->cash->assertOpen($issuedOn, 'first_installment_paid');
            }

            $entry = FinancialEntry::create([
                // O tipo decorre da categoria: não há como lançar "aluguel" como receita.
                'type' => $category->type(),
                'category' => $category,
                'description' => $description,
                'issued_on' => $issuedOn,
                'payment_method' => $paymentMethod,
                'patient_id' => $patient?->getKey(),
                'treatment_id' => $treatment?->getKey(),
                'treatment_plan_id' => $plan?->getKey(),
                'discount' => $discount,
                'created_by' => $createdBy?->getKey(),
                // Origem gerada pelo sistema: estorno, recorrência ou repasse de comissão.
                ...array_intersect_key($links, array_flip(['refund_of_id', 'recurring_expense_id', 'commission_payout_id'])),
            ]);

            $all = $paymentMethod->settlesAllInstallments($category->type());

            $rows = array_map(fn (array $installment) => [
                ...$installment,
                'paid_at' => $firstInstallmentPaid && ($all || $installment['number'] === 1) ? $issuedOn : null,
            ], InstallmentPlan::split($total, $installments, $firstDueDate));

            $entry->installments()->createMany($rows);

            return $entry;
        });
    }

    /**
     * Refaz as parcelas de um lançamento sem pagamento: nova forma, número de
     * parcelas e primeiro vencimento, mesmo total. Chamado com o lançamento
     * já travado pelo chamador, dentro da transação dele.
     */
    public function reschedule(FinancialEntry $entry, PaymentMethod $paymentMethod, int $installments, string $firstDueDate): void
    {
        $total = $entry->total();

        $entry->update(['payment_method' => $paymentMethod]);
        $entry->installments()->delete();
        $entry->installments()->createMany(InstallmentPlan::split($total, $installments, $firstDueDate));
    }
}
