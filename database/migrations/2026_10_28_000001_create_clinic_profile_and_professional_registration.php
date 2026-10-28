<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Identificação da clínica nos documentos (prontuário impresso, termo de
 * consentimento, orçamento) e o registro profissional de quem atende.
 *
 * A clínica é uma só: a tabela tem uma linha (id 1). Fica no banco, e não no
 * .env, porque o administrador corrige razão social, endereço ou responsável
 * técnico pela tela, sem acesso ao servidor. O nome exibido no menu continua
 * sendo APP_NAME, lido sem consulta em toda página.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clinic_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('legal_name', 150)->nullable();
            $table->char('cnpj', 14)->nullable();
            $table->string('address', 255)->nullable();
            $table->string('phone', 11)->nullable();
            $table->string('email', 150)->nullable();
            // Nome e registro no conselho de quem responde tecnicamente pela clínica.
            $table->string('technical_lead', 150)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            // Registro no conselho de classe (ex.: CRBM-1 12345), impresso junto do nome nos documentos.
            $table->string('professional_registration', 40)->nullable()->after('role');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('professional_registration');
        });

        Schema::dropIfExists('clinic_profiles');
    }
};
