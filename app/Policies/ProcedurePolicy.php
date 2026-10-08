<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Procedure;
use App\Models\User;

class ProcedurePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::ProceduresView);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permission::ProceduresManage);
    }

    public function update(User $user, Procedure $procedure): bool
    {
        return $user->hasPermission(Permission::ProceduresManage);
    }
}
