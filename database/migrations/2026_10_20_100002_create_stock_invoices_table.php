<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Notas fiscais já importadas, pela chave de acesso da NF-e (44
        // dígitos, que já contém CNPJ do emitente, série e número). O índice
        // único impede lançar a mesma nota duas vezes, inclusive em dois
        // envios simultâneos. O XML não é guardado; número e fornecedor
        // seguem nas próprias entradas de estoque.
        Schema::create('stock_invoices', function (Blueprint $table) {
            $table->id();
            $table->char('access_key', 44)->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_invoices');
    }
};
