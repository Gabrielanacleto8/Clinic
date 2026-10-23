<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Arquivar um paciente passou a cancelar os agendamentos em aberto dele.
 * Os arquivados antes disso ainda têm agendamentos em aberto, invisíveis na
 * agenda mas ocupando o horário do profissional: são cancelados aqui.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('appointments')
            ->whereIn('status', ['scheduled', 'confirmed'])
            ->whereIn('patient_id', fn ($query) => $query->select('id')->from('patients')->whereNotNull('deleted_at'))
            ->update(['status' => 'cancelled', 'updated_at' => now()]);
    }

    public function down(): void
    {
        // Não há como saber quais estavam em aberto: a correção não é desfeita.
    }
};
