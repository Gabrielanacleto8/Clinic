<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Cadastro do produto. Quantidade, lote e validade não ficam aqui:
        // variam por lote. Marca, fabricante e fornecedor são texto enquanto
        // não existir o módulo de Compras.
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150)->unique();
            $table->string('category', 20)->index();
            $table->string('brand', 100)->nullable();
            $table->string('manufacturer', 100)->nullable();
            $table->string('supplier', 150)->nullable();
            $table->string('unit', 5);
            $table->decimal('minimum_stock', 12, 3)->default(0);
            $table->decimal('cost', 10, 2)->nullable();
            $table->decimal('price', 10, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Um lote de um produto. "quantity" é o saldo do lote: deriva das
        // movimentações, mas fica gravado porque a baixa trava esta linha e
        // os alertas consultam o saldo a todo momento.
        Schema::create('product_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('batch_code', 60);
            $table->date('expires_on')->nullable()->index();
            $table->decimal('quantity', 12, 3)->default(0);
            $table->timestamps();

            $table->unique(['product_id', 'batch_code']);
        });

        // Livro de movimentações: só recebe inserções. "quantity" é a variação
        // com sinal (entrada positiva, saída negativa).
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_batch_id')->constrained()->restrictOnDelete();
            $table->string('type', 12);
            $table->decimal('quantity', 12, 3);
            $table->string('reason')->nullable();
            // Preenchido quando a baixa veio de um atendimento finalizado.
            $table->foreignId('treatment_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at');

            $table->index(['product_batch_id', 'id']);
        });

        // Produtos normalmente usados em um procedimento, com a quantidade estimada.
        Schema::create('procedure_product', function (Blueprint $table) {
            $table->foreignId('procedure_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->decimal('quantity', 12, 3);

            $table->primary(['procedure_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('procedure_product');
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('product_batches');
        Schema::dropIfExists('products');
    }
};
