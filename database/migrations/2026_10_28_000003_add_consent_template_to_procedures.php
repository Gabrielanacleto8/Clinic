<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Texto do termo de consentimento de cada procedimento. O termo impresso é
 * montado na hora com os dados do paciente e da clínica; o assinado volta
 * como documento do atendimento. Sem texto próprio, vale o modelo padrão.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('procedures', function (Blueprint $table) {
            $table->text('consent_template')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('procedures', function (Blueprint $table) {
            $table->dropColumn('consent_template');
        });
    }
};
