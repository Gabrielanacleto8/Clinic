<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Registro de atendimento. restrictOnDelete: prontuário não some por
        // efeito colateral da exclusão de um paciente ou de um usuário.
        Schema::create('treatments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained()->restrictOnDelete();
            $table->foreignId('professional_id')->constrained('users')->restrictOnDelete();
            $table->dateTime('performed_at');
            $table->string('procedure', 150);
            $table->string('treated_areas')->nullable();
            // Textos clínicos criptografados na aplicação.
            $table->text('description');
            $table->text('post_care')->nullable();
            $table->text('complications')->nullable();
            $table->date('return_date')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['patient_id', 'performed_at']);
        });

        // Produtos aplicados, com lote e validade para rastreabilidade.
        Schema::create('treatment_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('treatment_id')->constrained()->cascadeOnDelete();
            $table->string('name', 150);
            $table->string('batch', 60)->nullable();
            $table->date('expires_on')->nullable();
            $table->string('quantity', 60)->nullable();
            $table->timestamps();

            // Permite localizar todos os pacientes que receberam um lote.
            $table->index('batch');
        });

        Schema::create('treatment_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('treatment_id')->constrained()->restrictOnDelete();
            $table->string('stage', 20);
            $table->string('caption', 150)->nullable();
            // Caminhos internos no disco privado; nunca vêm do nome enviado pelo usuário.
            $table->string('path');
            $table->string('thumbnail_path');
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->unsignedInteger('size');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('treatment_photos');
        Schema::dropIfExists('treatment_products');
        Schema::dropIfExists('treatments');
    }
};
