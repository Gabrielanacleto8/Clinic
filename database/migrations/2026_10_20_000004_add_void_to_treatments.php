<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Atendimento registrado com erro (paciente, lote ou valor errado) é anulado,
 * nunca editado nem apagado: o registro continua no prontuário, marcado como
 * anulado, com quem anulou, quando e por quê. A anulação devolve o estoque,
 * desfaz o lançamento financeiro e reabre o agendamento, para o registro
 * certo ser feito de novo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('treatments', function (Blueprint $table) {
            $table->timestamp('voided_at')->nullable()->after('return_date');
            $table->foreignId('voided_by')->nullable()->after('voided_at')->constrained('users')->restrictOnDelete();
            // Gravado com criptografia: o motivo pode citar dado clínico.
            $table->text('void_reason')->nullable()->after('voided_by');
        });
    }

    public function down(): void
    {
        Schema::table('treatments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('voided_by');
            $table->dropColumn(['voided_at', 'void_reason']);
        });
    }
};
