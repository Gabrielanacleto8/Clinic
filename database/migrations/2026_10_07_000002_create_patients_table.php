<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patients', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150)->index();
            // CPF criptografado na aplicação; cpf_hash é o índice cego para busca e unicidade.
            $table->text('cpf');
            $table->char('cpf_hash', 64)->unique();
            $table->date('birth_date');
            $table->char('sex', 1)->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('email')->nullable();
            $table->char('zip_code', 8)->nullable();
            $table->string('street', 150)->nullable();
            $table->string('number', 20)->nullable();
            $table->string('complement', 100)->nullable();
            $table->string('district', 100)->nullable();
            $table->string('city', 100)->nullable();
            $table->char('state', 2)->nullable();
            // Observações criptografadas na aplicação.
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            // Prontuários têm prazo legal de guarda: pacientes são arquivados, não apagados.
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patients');
    }
};
