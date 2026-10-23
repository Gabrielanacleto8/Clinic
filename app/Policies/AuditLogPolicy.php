<?php

namespace App\Policies;

use App\Models\User;

/**
 * A trilha de auditoria mostra quem acessou o quê: só o administrador consulta.
 */
class AuditLogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }
}
