<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Orçamentos (planos de tratamento) e pacotes de sessões, numa entidade só:
 * o orçamento aprovado é o pacote. Cada item é um procedimento com o número
 * de sessões e o valor de cada uma.
 *
 * Nada derivado é gravado: o total é a soma dos itens e as sessões usadas
 * são os atendimentos não anulados ligados ao item (treatments.treatment_plan_item_id).
 * Assim, anular um atendimento devolve a sessão sem ajuste manual.
 *
 * A cobrança nasce na aprovação, uma vez, ligada ao plano
 * (financial_entries.treatment_plan_id, único); a sessão usada no atendimento
 * não gera outra.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('treatment_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained()->restrictOnDelete();
            $table->string('status', 12);
            // Até quando o orçamento em aberto vale; aprovado, deixa de importar.
            $table->date('valid_until')->nullable();
            // Observações para o paciente ou a equipe; criptografadas na aplicação.
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            // Recusado (orçamento) ou encerrado (pacote com sessões que não serão usadas).
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['patient_id', 'status']);
            $table->index(['status', 'id']);
        });

        Schema::create('treatment_plan_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('treatment_plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('procedure_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('sessions');
            $table->decimal('unit_price', 10, 2);
        });

        Schema::table('financial_entries', function (Blueprint $table) {
            $table->foreignId('treatment_plan_id')->nullable()->unique()->after('treatment_id')->constrained()->restrictOnDelete();
        });

        Schema::table('treatments', function (Blueprint $table) {
            $table->foreignId('treatment_plan_item_id')->nullable()->after('procedure_id')->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('treatments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('treatment_plan_item_id');
        });

        Schema::table('financial_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('treatment_plan_id');
        });

        Schema::dropIfExists('treatment_plan_items');
        Schema::dropIfExists('treatment_plans');
    }
};
