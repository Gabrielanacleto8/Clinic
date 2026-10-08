<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Padrão é o perfil de menor privilégio.
            $table->string('role', 20)->default('receptionist')->after('password');
            $table->boolean('is_active')->default(true)->after('role');
            // Nulo indica senha provisória: o usuário precisa trocá-la ao entrar.
            $table->timestamp('password_changed_at')->nullable()->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['role', 'is_active', 'password_changed_at']);
        });
    }
};
