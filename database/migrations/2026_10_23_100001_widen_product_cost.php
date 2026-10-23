<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * O custo de referência do produto passa a ter 4 casas, como o custo das
 * entradas: insumo barato (agulha a R$ 0,035) perdia precisão em 2 casas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('cost', 12, 4)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('cost', 10, 2)->nullable()->change();
        });
    }
};
