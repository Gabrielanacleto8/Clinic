<?php

namespace App\Services;

use App\Enums\FinancialCategory;
use App\Enums\PaymentMethod;
use App\Models\FinancialEntry;
use App\Models\Patient;
use App\Models\Treatment;
use App\Models\User;
use App\Support\ClinicTime;
use App\Support\InstallmentPlan;
use Illuminate\Support\Facades\DB;

/**
 * Único ponto que cria lançamentos financeiros: grava o lançamento e as
 * parcelas na mesma transação. Usado pelo lançamento manual e pelo
 * registro de atendimentos.
 */
class FinancialRecorder
{
    /**
     * @param  bool  $firstInstallmentPaid  A primeira parcela (ou o valor à vista) já foi paga na data de competência.
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
    ): FinancialEntry {
        return DB::transaction(function () use ($category, $description, $total, $paymentMethod, $installments, $firstDueDate, $firstInstallmentPaid, $createdBy, $issuedOn, $patient, $treatment): FinancialEntry {
            $issuedOn ??= ClinicTime::today();

            $entry = FinancialEntry::create([
                // O tipo decorre da categoria: não há como lançar "aluguel" como receita.
                'type' => $category->type(),
                'category' => $category,
                'description' => $description,
                'issued_on' => $issuedOn,
                'payment_method' => $paymentMethod,
                'patient_id' => $patient?->getKey(),
                'treatment_id' => $treatment?->getKey(),
                'created_by' => $createdBy?->getKey(),
            ]);

            $rows = array_map(fn (array $installment) => [
                ...$installment,
                'paid_at' => $firstInstallmentPaid && $installment['number'] === 1 ? $issuedOn : null,
            ], InstallmentPlan::split($total, $installments, $firstDueDate));

            $entry->installments()->createMany($rows);

            return $entry;
        });
    }
}
