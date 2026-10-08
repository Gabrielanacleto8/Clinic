<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained()->restrictOnDelete();
            $table->foreignId('professional_id')->constrained('users')->restrictOnDelete();
            $table->dateTime('scheduled_at');
            // Fim gravado (início + duração) para a checagem de conflito usar índice.
            $table->dateTime('ends_at');
            $table->unsignedSmallInteger('duration_minutes');
            $table->string('procedure', 150)->nullable();
            // Observação da agenda, criptografada na aplicação.
            $table->text('notes')->nullable();
            $table->string('status', 20)->default('scheduled');
            // Atendimento gerado a partir deste agendamento.
            $table->foreignId('treatment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('scheduled_at');
            $table->index(['professional_id', 'status', 'scheduled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointments');
    }
};
