<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Código interno ou de barras do produto: identificador próprio, que
        // não se deriva de nenhum outro dado.
        Schema::table('products', function (Blueprint $table) {
            $table->string('code', 40)->nullable()->unique()->after('name');
        });

        // Fabricação é do lote, como a validade: todas as entradas do mesmo
        // lote compartilham a mesma data.
        Schema::table('product_batches', function (Blueprint $table) {
            $table->date('manufactured_on')->nullable()->after('batch_code');
        });

        // Custo, fornecedor e nota fiscal são de cada entrada, e um lote pode
        // receber mais de uma. Ficam na própria movimentação, e não em uma
        // tabela 1:1, porque nascem e são lidos junto com ela.
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->decimal('unit_cost', 10, 2)->nullable()->after('quantity');
            $table->string('supplier', 150)->nullable()->after('unit_cost');
            $table->string('invoice_number', 60)->nullable()->after('supplier');

            // Abas de Entradas e de Movimentações: filtro por tipo e por período.
            $table->index(['type', 'id']);
            $table->index('created_at');

            // Um atendimento baixa cada lote uma única vez: garante no banco
            // que a finalização nunca duplica o consumo.
            $table->unique(['treatment_id', 'product_batch_id']);
        });

        // Consumo em atendimento passa a ter tipo próprio; antes era uma saída
        // ligada a um atendimento.
        DB::table('stock_movements')->where('type', 'exit')->whereNotNull('treatment_id')->update(['type' => 'consumption']);
    }

    public function down(): void
    {
        DB::table('stock_movements')->whereIn('type', ['consumption', 'loss'])->update(['type' => 'exit']);

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropUnique(['treatment_id', 'product_batch_id']);
            $table->dropIndex(['created_at']);
            $table->dropIndex(['type', 'id']);
            $table->dropColumn(['unit_cost', 'supplier', 'invoice_number']);
        });

        Schema::table('product_batches', function (Blueprint $table) {
            $table->dropColumn('manufactured_on');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropUnique(['code']);
            $table->dropColumn('code');
        });
    }
};
