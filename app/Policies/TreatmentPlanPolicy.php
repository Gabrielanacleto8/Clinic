<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\TreatmentPlan;
use App\Models\User;

/**
 * Orçamentos e pacotes. Em aberto, o orçamento é editado à vontade; aprovado,
 * vira histórico: os itens não mudam, só as sessões são usadas ou o pacote encerrado.
 */
class TreatmentPlanPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::PlansView);
    }

    public function view(User $user, TreatmentPlan $plan): bool
    {
        return $user->hasPermission(Permission::PlansView);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permission::PlansManage);
    }

    public function update(User $user, TreatmentPlan $plan): bool
    {
        return $user->hasPermission(Permission::PlansManage) && $plan->isOpen();
    }

    /**
     * Aprovar gera a cobrança do pacote, como o registro de atendimento gera a dele.
     */
    public function approve(User $user, TreatmentPlan $plan): bool
    {
        return $user->hasPermission(Permission::PlansManage) && $plan->isOpen();
    }

    public function decline(User $user, TreatmentPlan $plan): bool
    {
        return $user->hasPermission(Permission::PlansManage) && $plan->isOpen();
    }

    /**
     * Encerrar um pacote aprovado: as sessões restantes deixam de valer. O
     * financeiro não muda sozinho; devolução é decidida no financeiro.
     */
    public function close(User $user, TreatmentPlan $plan): bool
    {
        return $user->hasPermission(Permission::PlansManage) && $plan->isApproved();
    }
}
