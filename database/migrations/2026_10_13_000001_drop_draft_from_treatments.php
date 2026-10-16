<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * O atendimento passa a ser registrado em um passo só, já com a baixa de
     * estoque e o financeiro. Sem rascunho, a situação e a data de finalização
     * deixam de dizer algo: todo registro é definitivo desde que nasce.
     */
    public function up(): void
    {
        // Rascunhos pendentes nunca baixaram estoque nem geraram financeiro.
        // Saem do prontuário como no antigo "Descartar": soft delete, com o
        // agendamento de origem liberado para um novo registro.
        $drafts = DB::table('treatments')->where('status', 'draft');

        DB::table('appointments')->whereIn('treatment_id', (clone $drafts)->select('id'))->update(['treatment_id' => null]);
        $drafts->whereNull('deleted_at')->update(['deleted_at' => now()]);

        Schema::table('treatments', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropColumn(['status', 'finalized_at']);
        });
    }

    public function down(): void
    {
        Schema::table('treatments', function (Blueprint $table) {
            $table->string('status', 12)->default('draft')->after('procedure_id')->index();
            $table->dateTime('finalized_at')->nullable()->after('status');
        });

        // Todo registro ativo é definitivo. Os rascunhos descartados em up() seguem descartados.
        DB::table('treatments')->whereNull('deleted_at')->update(['status' => 'finalized', 'finalized_at' => DB::raw('created_at')]);
    }
};
