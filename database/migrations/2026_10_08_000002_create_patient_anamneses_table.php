<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Ficha de saúde: uma por paciente. Campos de texto criptografados na aplicação.
        Schema::create('patient_anamneses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->unique()->constrained()->restrictOnDelete();
            $table->text('allergies')->nullable();
            $table->text('medications')->nullable();
            $table->text('health_conditions')->nullable();
            $table->text('previous_procedures')->nullable();
            $table->boolean('pregnant_or_lactating')->default(false);
            $table->text('notes')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_anamneses');
    }
};
