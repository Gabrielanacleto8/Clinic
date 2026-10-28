<?php

namespace App\Services;

use App\Enums\FinancialEntryType;
use App\Models\FinancialInstallment;
use App\Models\Patient;
use App\Support\ClinicTime;
use Illuminate\Database\Eloquent\Builder;

/**
 * Parcelas que vencem ou foram pagas no período, com os filtros do
 * financeiro. A mesma consulta serve à tela, ao total do rodapé e à planilha.
 */
class FinanceListing
{
    /**
     * @return Builder<FinancialInstallment>
     */
    public function query(string $start, string $end, string $type, string $status, string $patientTerm): Builder
    {
        $today = ClinicTime::today();

        return FinancialInstallment::query()
            ->select('financial_installments.*')
            ->join('financial_entries', 'financial_entries.id', '=', 'financial_installments.financial_entry_id')
            // "Vencidas (todos os meses)" ignora o período: é a lista de cobrança.
            ->when($status !== 'vencidas_todas', fn ($query) => $query->where(
                fn ($inner) => $inner->whereBetween('due_date', [$start, $end])->orWhereBetween('paid_at', [$start, $end]),
            ))
            ->when($type === 'receitas', fn ($query) => $query->where('financial_entries.type', FinancialEntryType::Income->value))
            ->when($type === 'despesas', fn ($query) => $query->where('financial_entries.type', FinancialEntryType::Expense->value))
            ->when($status === 'pagas', fn ($query) => $query->whereNotNull('paid_at'))
            ->when($status === 'abertas', fn ($query) => $query->whereNull('paid_at'))
            ->when($status === 'pendentes', fn ($query) => $query->whereNull('paid_at')->where('due_date', '>=', $today))
            ->when(in_array($status, ['vencidas', 'vencidas_todas'], true), fn ($query) => $query->whereNull('paid_at')->where('due_date', '<', $today))
            ->when($patientTerm !== '', fn ($query) => $query->whereIn(
                'financial_entries.patient_id',
                Patient::withTrashed()->search($patientTerm)->select('id'),
            ))
            ->orderBy('due_date')
            ->orderBy('financial_installments.id');
    }
}
