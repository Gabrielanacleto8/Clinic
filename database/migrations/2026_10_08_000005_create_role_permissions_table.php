<?php

use App\Enums\Permission;
use App\Enums\UserRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Uma linha por permissão concedida. A chave composta dispensa id
        // próprio e já impede concessão repetida.
        Schema::create('role_permissions', function (Blueprint $table) {
            $table->string('role', 20);
            $table->string('permission', 40);

            $table->primary(['role', 'permission']);
        });

        $rows = [];

        foreach (UserRole::configurable() as $role) {
            foreach (Permission::defaultsFor($role) as $permission) {
                $rows[] = ['role' => $role->value, 'permission' => $permission->value];
            }
        }

        DB::table('role_permissions')->insert($rows);
    }

    public function down(): void
    {
        Schema::dropIfExists('role_permissions');
    }
};
