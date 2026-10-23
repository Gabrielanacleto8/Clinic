<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Custo por unidade de medida com quatro casas: insumo barato (agulha
        // a R$ 0,085) ou custo de frasco dividido em unidades (R$ 950,50 por
        // 100 U) perderia até 6% com duas casas, e o erro se multiplica no
        // custo do consumo e no valor perdido. Exibição continua em centavos.
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->decimal('unit_cost', 14, 4)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->decimal('unit_cost', 10, 2)->nullable()->change();
        });
    }
};
