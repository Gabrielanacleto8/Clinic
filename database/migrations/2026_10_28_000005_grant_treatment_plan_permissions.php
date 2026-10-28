<?php

use App\Enums\Permission;
use App\Enums\UserRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Orçamentos e pacotes são vendidos tanto pela recepção quanto por quem
 * atende: os dois perfis padrão recebem ver e gerenciar, como numa
 * instalação nova. O administrador pode retirar em Usuários › Permissões.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach ([UserRole::Professional, UserRole::Receptionist] as $role) {
            foreach ([Permission::PlansView, Permission::PlansManage] as $permission) {
                $row = ['role' => $role->value, 'permission' => $permission->value];
                DB::table('role_permissions')->updateOrInsert($row, $row);
            }
        }
    }

    public function down(): void
    {
        DB::table('role_permissions')->whereIn('permission', [Permission::PlansView->value, Permission::PlansManage->value])->delete();
    }
};
