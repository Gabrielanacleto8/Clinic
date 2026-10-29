<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Financeiro completo: desconto, estorno, despesa recorrente, conciliação,
 * fechamento de caixa e comissões.
 *
 * Valores gravados que poderiam parecer derivados, e por quê:
 * - financial_entries.discount e treatment_plan_items.list_price: o preço de
 *   tabela muda com o catálogo; o desconto concedido é o do dia da venda.
 * - treatments.commission_rate: a regra de comissão muda; o atendimento
 *   guarda a que valia quando foi feito.
 * - cash_closing_lines.expected: é o que o caixa mostrava na conferência. O
 *   fechamento é o registro dessa conferência, não um total recalculável.
 * - commission_payout_items.amount: o que foi pago a cada atendimento num
 *   repasse; o pendente é o devido hoje menos a soma dos repasses.
 * - recurring_expenses.generated_until: até que mês a recorrência já gerou
 *   despesa. Sem ela, excluir uma despesa gerada a faria nascer de novo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('financial_entries', function (Blueprint $table) {
            // Desconto sobre o preço de tabela, em reais. O total cobrado é a soma das parcelas.
            $table->decimal('discount', 12, 2)->default(0)->after('payment_method');
            // Estorno: devolução ao paciente de parte do que ele pagou neste lançamento.
            $table->foreignId('refund_of_id')->nullable()->after('treatment_plan_id')->constrained('financial_entries')->restrictOnDelete();
        });

        Schema::table('financial_installments', function (Blueprint $table) {
            // Conciliação: o recebimento conferido no extrato, na data em que caiu, com a taxa da operadora.
            $table->date('reconciled_on')->nullable()->after('paid_at');
            $table->decimal('fee', 12, 2)->nullable()->after('reconciled_on');
            $table->foreignId('reconciled_by')->nullable()->after('fee')->constrained('users')->nullOnDelete();

            $table->index('reconciled_on');
        });

        Schema::create('recurring_expenses', function (Blueprint $table) {
            $table->id();
            $table->string('category', 20);
            $table->string('description', 200);
            $table->decimal('amount', 12, 2);
            $table->string('payment_method', 20);
            // Dia do vencimento em cada mês; até 28 para existir em todos.
            $table->unsignedTinyInteger('due_day');
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->date('generated_until')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('financial_entries', function (Blueprint $table) {
            $table->foreignId('recurring_expense_id')->nullable()->after('refund_of_id')->constrained()->nullOnDelete();
        });

        Schema::create('cash_closings', function (Blueprint $table) {
            $table->id();
            // Um fechamento por dia. Dia fechado não recebe pagamento novo nem desfeito.
            $table->date('day')->unique();
            $table->string('notes', 500)->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('cash_closing_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cash_closing_id')->constrained()->cascadeOnDelete();
            $table->string('payment_method', 20);
            $table->decimal('expected', 12, 2);
            $table->decimal('counted', 12, 2);

            $table->unique(['cash_closing_id', 'payment_method']);
        });

        Schema::create('role_discount_limits', function (Blueprint $table) {
            // Desconto máximo, em % do preço de tabela, que cada perfil concede. O administrador não tem limite.
            $table->string('role', 20)->primary();
            $table->decimal('max_percent', 5, 2);
        });

        DB::table('role_discount_limits')->insert([
            ['role' => 'professional', 'max_percent' => 10],
            ['role' => 'receptionist', 'max_percent' => 10],
        ]);

        Schema::table('treatment_plan_items', function (Blueprint $table) {
            $table->decimal('list_price', 10, 2)->nullable()->after('unit_price');
        });

        // Itens já gravados: sem registro do preço de tabela, contam como vendidos sem desconto.
        DB::table('treatment_plan_items')->update(['list_price' => DB::raw('unit_price')]);

        Schema::create('commission_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Sem procedimento: o % padrão do profissional. Com procedimento: a exceção para ele.
            // Restrict: o MySQL não aceita cascata em coluna usada pela coluna gerada abaixo (procedimento não é excluído).
            $table->foreignId('procedure_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('procedure_key')->storedAs('ifnull(procedure_id, 0)');
            $table->decimal('rate', 5, 2);
            $table->timestamps();

            $table->unique(['user_id', 'procedure_key']);
        });

        Schema::table('treatments', function (Blueprint $table) {
            $table->decimal('commission_rate', 5, 2)->nullable()->after('treatment_plan_item_id');
        });

        Schema::create('commission_payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('professional_id')->constrained('users')->restrictOnDelete();
            // Atendimentos feitos até esta data entram no repasse.
            $table->date('until');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('commission_payout_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('commission_payout_id')->constrained()->cascadeOnDelete();
            $table->foreignId('treatment_id')->constrained()->restrictOnDelete();
            // Valor recebido que serviu de base, % aplicado e o que foi pago neste repasse (pode ser negativo: acerto).
            $table->decimal('base', 12, 2);
            $table->decimal('rate', 5, 2);
            $table->decimal('amount', 12, 2);

            $table->unique(['commission_payout_id', 'treatment_id']);
        });

        Schema::table('financial_entries', function (Blueprint $table) {
            $table->foreignId('commission_payout_id')->nullable()->unique()->after('recurring_expense_id')->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('financial_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('commission_payout_id');
        });
        Schema::dropIfExists('commission_payout_items');
        Schema::dropIfExists('commission_payouts');
        Schema::table('treatments', function (Blueprint $table) {
            $table->dropColumn('commission_rate');
        });
        Schema::dropIfExists('commission_rates');
        Schema::table('treatment_plan_items', function (Blueprint $table) {
            $table->dropColumn('list_price');
        });
        Schema::dropIfExists('role_discount_limits');
        Schema::dropIfExists('cash_closing_lines');
        Schema::dropIfExists('cash_closings');
        Schema::table('financial_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('recurring_expense_id');
            $table->dropConstrainedForeignId('refund_of_id');
            $table->dropColumn('discount');
        });
        Schema::dropIfExists('recurring_expenses');
        Schema::table('financial_installments', function (Blueprint $table) {
            $table->dropIndex(['reconciled_on']);
            $table->dropConstrainedForeignId('reconciled_by');
            $table->dropColumn(['reconciled_on', 'fee']);
        });
    }
};
