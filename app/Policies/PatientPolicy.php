<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Patient;
use App\Models\User;

class PatientPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::PatientsView);
    }

    public function view(User $user, Patient $patient): bool
    {
        return $user->hasPermission(Permission::PatientsView);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permission::PatientsManage);
    }

    public function update(User $user, Patient $patient): bool
    {
        return $user->hasPermission(Permission::PatientsManage);
    }

    public function delete(User $user, Patient $patient): bool
    {
        return $user->hasPermission(Permission::PatientsArchive);
    }

    public function viewArchived(User $user): bool
    {
        return $user->hasPermission(Permission::PatientsArchive);
    }

    public function restore(User $user, Patient $patient): bool
    {
        return $user->hasPermission(Permission::PatientsArchive);
    }

    /**
     * Ver a ficha de saúde e a lista de atendimentos do paciente.
     */
    public function viewClinicalRecords(User $user, Patient $patient): bool
    {
        return $user->hasPermission(Permission::RecordsView);
    }

    public function updateClinicalRecords(User $user, Patient $patient): bool
    {
        return $user->hasPermission(Permission::RecordsManage);
    }

    /**
     * Enviar fotos avulsas pela ficha, por exemplo o acervo anterior ao sistema.
     */
    public function attachPhotos(User $user, Patient $patient): bool
    {
        return $user->hasPermission(Permission::RecordsManage);
    }

    /**
     * Remover fotos: só o administrador.
     */
    public function detachPhotos(User $user, Patient $patient): bool
    {
        return $user->isAdmin();
    }
}
