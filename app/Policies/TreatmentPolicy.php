<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Treatment;
use App\Models\User;

/**
 * Um atendimento registrado é histórico permanente: ninguém o altera nem o
 * exclui. Só se acrescentam evoluções, fotos e documentos.
 */
class TreatmentPolicy
{
    /**
     * Lista de atendimentos. Mostra quem fez qual procedimento, por isso
     * segue a permissão do prontuário, e não a da agenda.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::RecordsView);
    }

    public function view(User $user, Treatment $treatment): bool
    {
        return $user->hasPermission(Permission::RecordsView);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permission::RecordsManage);
    }

    /**
     * Enviar fotos e documentos. Qualquer profissional pode acrescentar, por
     * exemplo as fotos do retorno.
     */
    public function attach(User $user, Treatment $treatment): bool
    {
        return $user->hasPermission(Permission::RecordsManage);
    }

    /**
     * Remover fotos e documentos: só o administrador.
     */
    public function detach(User $user, Treatment $treatment): bool
    {
        return $user->isAdmin();
    }

    /**
     * Registrar evolução ou adendo.
     */
    public function addNote(User $user, Treatment $treatment): bool
    {
        return $user->hasPermission(Permission::RecordsManage);
    }
}
