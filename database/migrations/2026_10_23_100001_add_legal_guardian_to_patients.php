<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Responsável legal do paciente menor de idade: quem autoriza o procedimento
 * e o uso de imagem (LGPD, art. 14) e quem a clínica contata. São fatos
 * novos, não derivados de nada já gravado. O CPF do responsável é
 * criptografado como o do paciente; não é chave de busca, então não tem
 * índice cego.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->string('guardian_name', 150)->nullable()->after('image_use_consented_at');
            $table->text('guardian_cpf')->nullable()->after('guardian_name');
            $table->string('guardian_relationship', 50)->nullable()->after('guardian_cpf');
            $table->string('guardian_phone', 11)->nullable()->after('guardian_relationship');
        });
    }

    public function down(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->dropColumn(['guardian_name', 'guardian_cpf', 'guardian_relationship', 'guardian_phone']);
        });
    }
};
