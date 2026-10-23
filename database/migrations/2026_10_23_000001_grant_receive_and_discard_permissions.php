<?php

use App\Enums\Permission;
use App\Enums\UserRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Permissões novas, separadas das que davam acesso demais: receber a cobrança
 * de um paciente não exige o financeiro inteiro, e descartar uma unidade
 * vencida não exige a gestão de compras. Quem já tinha finance.manage ganha
 * finance.receive (é parte dela). Os perfis padrão recebem o que a instalação
 * daria hoje: recepção recebe; profissional descarta.
 */
return new class extends Migration
{
    public function up(): void
    {
        $rows = [];

        foreach (DB::table('role_permissions')->where('permission', Permission::FinanceManage->value)->pluck('role') as $role) {
            $rows[] = ['role' => $role, 'permission' => Permission::FinanceReceive->value];
        }

        $rows[] = ['role' => UserRole::Receptionist->value, 'permission' => Permission::FinanceReceive->value];
        $rows[] = ['role' => UserRole::Professional->value, 'permission' => Permission::StockDiscard->value];

        foreach ($rows as $row) {
            DB::table('role_permissions')->updateOrInsert($row, $row);
        }
    }

    public function down(): void
    {
        DB::table('role_permissions')->whereIn('permission', [Permission::FinanceReceive->value, Permission::StockDiscard->value])->delete();
    }
};
