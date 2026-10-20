<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Produto fracionado: cada unidade física (frasco, seringa, ampola) é
        // aberta e usada aos poucos. Sem conteúdo informado, o produto segue
        // controlado só por lote, como antes.
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('unit_content', 12, 3)->nullable()->after('unit');
            $table->unsignedSmallInteger('hours_after_opening')->nullable()->after('unit_content');
            $table->boolean('multi_patient_use')->default(false)->after('hours_after_opening');
        });

        // Unidade física aberta de um lote. Lote, validade e produto vêm do
        // lote; o prazo depois de aberta e o uso em vários pacientes vêm do
        // produto, para uma correção no cadastro valer para as já abertas.
        Schema::create('stock_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_batch_id')->constrained()->restrictOnDelete();
            // O conteúdo é do frasco que foi aberto: gravado porque o cadastro
            // do produto pode ser corrigido depois sem mudar o que existiu.
            $table->decimal('initial_quantity', 12, 3);
            // Saldo da unidade. Deriva das movimentações, mas fica gravado pelo
            // mesmo motivo do saldo do lote: a baixa trava esta linha.
            $table->decimal('quantity', 12, 3);
            $table->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('opened_at');
            $table->timestamp('updated_at')->nullable();

            $table->index(['product_batch_id', 'quantity']);
        });

        // Cada baixa de produto fracionado aponta a unidade física de onde saiu.
        // "unit_cost" passa a ser gravado também nas saídas: é o custo médio do
        // lote no momento da baixa, que entradas posteriores mudariam.
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->foreignId('stock_unit_id')->nullable()->after('product_batch_id')->constrained()->restrictOnDelete();
            // Unicidade com unidade opcional: o MySQL aceita nulos repetidos em
            // índice único, então a chave usa 0 para "sem unidade".
            $table->unsignedBigInteger('stock_unit_key')->storedAs('ifnull(stock_unit_id, 0)')->after('stock_unit_id');

            // Um atendimento consome cada unidade (ou lote não fracionado) uma
            // única vez. O tipo entra para o descarte da sobra ficar ligado ao
            // mesmo atendimento.
            $table->unique(['treatment_id', 'product_batch_id', 'stock_unit_key', 'type'], 'stock_movements_treatment_consumption_unique');
            $table->dropUnique(['treatment_id', 'product_batch_id']);
        });

        Schema::table('treatment_products', function (Blueprint $table) {
            $table->foreignId('stock_unit_id')->nullable()->after('product_batch_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('stock_unit_key')->storedAs('ifnull(stock_unit_id, 0)')->after('stock_unit_id');

            // Duas unidades do mesmo lote podem entrar no mesmo atendimento
            // (a sobra de um frasco e um frasco novo); a mesma unidade, não.
            $table->unique(['treatment_id', 'product_batch_id', 'stock_unit_key'], 'treatment_products_treatment_unit_unique');
            $table->dropUnique(['treatment_id', 'product_batch_id']);
        });
    }

    public function down(): void
    {
        Schema::table('treatment_products', function (Blueprint $table) {
            $table->unique(['treatment_id', 'product_batch_id']);
            $table->dropUnique('treatment_products_treatment_unit_unique');
            $table->dropColumn('stock_unit_key');
            $table->dropConstrainedForeignId('stock_unit_id');
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->unique(['treatment_id', 'product_batch_id']);
            $table->dropUnique('stock_movements_treatment_consumption_unique');
            $table->dropColumn('stock_unit_key');
            $table->dropConstrainedForeignId('stock_unit_id');
        });

        Schema::dropIfExists('stock_units');

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['unit_content', 'hours_after_opening', 'multi_patient_use']);
        });
    }
};
