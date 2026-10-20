<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Foto de identificação do paciente (avatar). Não é foto clínica: fica
        // fora de patient_attachments para não entrar na galeria do prontuário
        // e para ser vista por quem vê o cadastro. O arquivo fica criptografado
        // no cofre; aqui só o caminho interno.
        Schema::table('patients', function (Blueprint $table) {
            $table->string('avatar_path')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->dropColumn('avatar_path');
        });
    }
};
