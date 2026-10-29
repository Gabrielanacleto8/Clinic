<?php

use App\Enums\Permission;
use App\Enums\UserRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fechar o caixa do dia é tarefa de quem recebe: a recepção que recebe
 * cobranças ganha a permissão, como numa instalação nova, e quem já gerencia
 * o financeiro também.
 */
return new class extends Migration
{
    public function up(): void
    {
        $roles = DB::table('role_permissions')->where('permission', Permission::FinanceManage->value)->pluck('role')->all();

        // A recepção, se ainda recebe cobranças (fechar o caixa depende disso).
        if (DB::table('role_permissions')->where(['role' => UserRole::Receptionist->value, 'permission' => Permission::FinanceReceive->value])->exists()) {
            $roles[] = UserRole::Receptionist->value;
        }

        foreach (array_unique($roles) as $role) {
            $row = ['role' => $role, 'permission' => Permission::FinanceCash->value];
            DB::table('role_permissions')->updateOrInsert($row, $row);
        }
    }

    public function down(): void
    {
        DB::table('role_permissions')->where('permission', Permission::FinanceCash->value)->delete();
    }
};
