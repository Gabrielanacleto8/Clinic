<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Receitas e despesas na mesma tabela, diferenciadas por "type". O
        // valor total não é gravado: é a soma das parcelas.
        Schema::create('financial_entries', function (Blueprint $table) {
            $table->id();
            $table->string('type', 10);
            $table->string('category', 20);
            $table->string('description', 200);
            // Data de competência: a que período o lançamento pertence.
            $table->date('issued_on');
            $table->string('payment_method', 20);
            $table->foreignId('patient_id')->nullable()->constrained()->restrictOnDelete();
            // Um atendimento gera no máximo um lançamento.
            $table->foreignId('treatment_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['type', 'issued_on']);
        });

        // Parcelas. Pagamento à vista é uma parcela só. Não há coluna de
        // situação: pago é ter "paid_at"; vencido é estar em aberto após "due_date".
        Schema::create('financial_installments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('financial_entry_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('number');
            $table->decimal('amount', 12, 2);
            $table->date('due_date')->index();
            $table->date('paid_at')->nullable()->index();
            $table->timestamps();

            $table->unique(['financial_entry_id', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_installments');
        Schema::dropIfExists('financial_entries');
    }
};
