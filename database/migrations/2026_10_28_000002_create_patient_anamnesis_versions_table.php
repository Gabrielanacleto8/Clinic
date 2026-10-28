<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Versões anteriores da ficha de saúde. A ficha continua uma por paciente
 * (patient_anamneses); a cada alteração, o conteúdo que ela tinha antes é
 * guardado aqui, para se saber o que o paciente havia declarado em cada data.
 * Tabela própria porque é 1:N e só cresce: nada aqui é alterado depois.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patient_anamnesis_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_anamnesis_id')->constrained('patient_anamneses')->restrictOnDelete();
            // Todos os campos da ficha, em JSON criptografado na aplicação.
            $table->text('content');
            // Quem gravou aquela versão e quando: o updated_by e o updated_at da ficha na época.
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('recorded_at')->nullable();
            // Quando ela deixou de valer (a alteração seguinte).
            $table->timestamp('created_at');

            $table->index(['patient_anamnesis_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_anamnesis_versions');
    }
};
