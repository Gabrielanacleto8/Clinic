<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('procedures', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150)->unique();
            $table->text('description')->nullable();
            $table->string('category', 20)->index();
            $table->decimal('price', 10, 2);
            $table->unsignedSmallInteger('duration_minutes');
            // Procedimento com histórico não é apagado: é desativado.
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // O procedimento era texto livre repetido em cada agendamento e
        // atendimento. Passa a ser uma referência ao catálogo. A troca só é
        // segura sem registros, porque texto livre não se converte sozinho.
        foreach (['appointments', 'treatments'] as $table) {
            if (DB::table($table)->whereNotNull('procedure')->exists()) {
                throw new RuntimeException("A tabela {$table} tem procedimentos em texto livre. Cadastre-os no catálogo e vincule os registros antes de migrar.");
            }
        }

        Schema::table('appointments', function (Blueprint $table) {
            $table->dropColumn('procedure');
            // Opcional: dá para agendar com o procedimento ainda a definir.
            $table->foreignId('procedure_id')->nullable()->after('professional_id')->constrained()->restrictOnDelete();
        });

        Schema::table('treatments', function (Blueprint $table) {
            $table->dropColumn('procedure');
            $table->foreignId('procedure_id')->after('professional_id')->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('treatments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('procedure_id');
            $table->string('procedure', 150)->default('');
        });

        Schema::table('appointments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('procedure_id');
            $table->string('procedure', 150)->nullable();
        });

        Schema::dropIfExists('procedures');
    }
};
