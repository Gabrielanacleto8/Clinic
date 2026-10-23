<?php

namespace App\Policies;

use App\Enums\FinancialEntryType;
use App\Enums\Permission;
use App\Models\FinancialEntry;
use App\Models\User;

class FinancialEntryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::FinanceView);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permission::FinanceManage);
    }

    /**
     * Desfazer o pagamento de uma parcela.
     */
    public function settle(User $user): bool
    {
        return $user->hasPermission(Permission::FinanceManage);
    }

    /**
     * Registrar o recebimento de uma parcela. Receita de paciente pode ser
     * recebida pela recepção, sem acesso ao resto do financeiro; despesa
     * continua com quem lança.
     */
    public function receive(User $user, FinancialEntry $entry): bool
    {
        return $entry->type === FinancialEntryType::Income
            ? $user->hasPermission(Permission::FinanceReceive)
            : $user->hasPermission(Permission::FinanceManage);
    }

    /**
     * Lançamento gerado por atendimento faz parte do histórico do atendimento
     * e não é excluído por aqui. Com parcela paga, o pagamento é desfeito
     * antes: excluir sumiria com dinheiro já contado em meses fechados.
     */
    public function delete(User $user, FinancialEntry $entry): bool
    {
        return $user->hasPermission(Permission::FinanceManage) && $entry->treatment_id === null && ! $entry->hasPaidInstallment();
    }

    /**
     * Editar descrição, categoria e vencimentos de um lançamento manual.
     * Valor e parcelas não mudam: exclui e relança.
     */
    public function update(User $user, FinancialEntry $entry): bool
    {
        return $user->hasPermission(Permission::FinanceManage) && $entry->treatment_id === null;
    }

    /**
     * Ajustar forma de pagamento, parcelas e vencimento da cobrança de um
     * atendimento, enquanto nada foi recebido. O registro clínico não muda.
     */
    public function adjustCharge(User $user, FinancialEntry $entry): bool
    {
        return $user->hasPermission(Permission::FinanceManage) && $entry->treatment_id !== null && ! $entry->hasPaidInstallment();
    }
}
