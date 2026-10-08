<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Appointment;
use App\Models\User;

class AppointmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::AgendaView);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permission::AgendaManage);
    }

    /**
     * Só agendamentos em aberto são remarcados. Os encerrados ficam como histórico.
     */
    public function update(User $user, Appointment $appointment): bool
    {
        return $user->hasPermission(Permission::AgendaManage) && $appointment->isOpen();
    }

    public function changeStatus(User $user, Appointment $appointment): bool
    {
        return $user->hasPermission(Permission::AgendaManage);
    }

    public function startTreatment(User $user, Appointment $appointment): bool
    {
        // Em aberto e ainda sem atendimento iniciado.
        return $user->hasPermission(Permission::RecordsManage) && $appointment->isOpen() && $appointment->treatment_id === null;
    }
}
