<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Autorização do paciente para uso da imagem dele em divulgação (redes
 * sociais, portfólio). Foto clínica no prontuário não depende dela: é parte
 * do registro do atendimento. Guarda quando a autorização foi registrada;
 * nulo é não autorizado. O termo assinado entra como documento.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->timestamp('image_use_consented_at')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->dropColumn('image_use_consented_at');
        });
    }
};
