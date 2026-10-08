<?php

namespace App\Policies;

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
     * Registrar ou desfazer o pagamento de uma parcela.
     */
    public function settle(User $user): bool
    {
        return $user->hasPermission(Permission::FinanceManage);
    }

    /**
     * Lançamento gerado por atendimento faz parte do histórico do atendimento
     * e não é excluído por aqui.
     */
    public function delete(User $user, FinancialEntry $entry): bool
    {
        return $user->hasPermission(Permission::FinanceManage) && $entry->treatment_id === null;
    }
}
