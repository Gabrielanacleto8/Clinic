<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Fotos passam a pertencer ao paciente: podem ser retroativas, de antes
        // do sistema ou de um procedimento feito fora dele, sem atendimento
        // registrado. Documentos continuam exigindo atendimento.
        Schema::rename('treatment_attachments', 'patient_attachments');

        // O modelo foi renomeado junto: a auditoria dos anexos antigos acompanha.
        DB::table('audit_logs')->where('auditable_type', 'App\\Models\\TreatmentAttachment')->update(['auditable_type' => 'App\\Models\\PatientAttachment']);

        Schema::table('patient_attachments', function (Blueprint $table) {
            // Para os anexos de atendimento o paciente é derivável do próprio
            // atendimento. Fica gravado porque é o dono do anexo: é por ele que as
            // rotas /pacientes/{patient}/fotos e a galeria chegam a qualquer foto,
            // com ou sem atendimento, numa única relação e índice.
            $table->foreignId('patient_id')->nullable()->after('id')->constrained()->restrictOnDelete();
            // Data da foto quando não há atendimento; com atendimento, vale a data dele.
            $table->date('taken_on')->nullable()->after('stage');
        });

        DB::table('patient_attachments as a')
            ->join('treatments as t', 't.id', '=', 'a.treatment_id')
            ->update(['a.patient_id' => DB::raw('t.patient_id')]);

        // Cada bloco faz uma coisa só: o MySQL não aceita alterar a coluna e a
        // chave estrangeira dela no mesmo ALTER.
        Schema::table('patient_attachments', function (Blueprint $table) {
            $table->unsignedBigInteger('patient_id')->nullable(false)->change();
        });

        Schema::table('patient_attachments', function (Blueprint $table) {
            // A chave ainda tem o nome da tabela original, de antes das duas renomeações.
            $table->dropForeign('treatment_photos_treatment_id_foreign');
        });

        Schema::table('patient_attachments', function (Blueprint $table) {
            $table->unsignedBigInteger('treatment_id')->nullable()->change();
        });

        Schema::table('patient_attachments', function (Blueprint $table) {
            $table->foreign('treatment_id')->references('id')->on('treatments')->restrictOnDelete();
        });

        // Ou a foto é de um atendimento, ou tem data própria; documento sempre tem atendimento.
        DB::statement('ALTER TABLE patient_attachments ADD CONSTRAINT patient_attachments_dated_check CHECK ((treatment_id IS NULL) = (taken_on IS NOT NULL))');
        DB::statement("ALTER TABLE patient_attachments ADD CONSTRAINT patient_attachments_document_check CHECK (type = 'photo' OR treatment_id IS NOT NULL)");
    }

    public function down(): void
    {
        // Foto avulsa não tem para onde voltar no esquema antigo, e dado de
        // paciente não se apaga: a reversão exige tratamento manual antes.
        if (DB::table('patient_attachments')->whereNull('treatment_id')->exists()) {
            throw new RuntimeException('Há fotos avulsas de pacientes. Elas não cabem no esquema anterior; trate-as antes de reverter.');
        }

        DB::table('audit_logs')->where('auditable_type', 'App\\Models\\PatientAttachment')->update(['auditable_type' => 'App\\Models\\TreatmentAttachment']);

        DB::statement('ALTER TABLE patient_attachments DROP CONSTRAINT patient_attachments_dated_check');
        DB::statement('ALTER TABLE patient_attachments DROP CONSTRAINT patient_attachments_document_check');

        Schema::table('patient_attachments', function (Blueprint $table) {
            $table->dropForeign(['treatment_id']);
        });

        Schema::table('patient_attachments', function (Blueprint $table) {
            $table->unsignedBigInteger('treatment_id')->nullable(false)->change();
        });

        Schema::table('patient_attachments', function (Blueprint $table) {
            // Com o nome original, para o up() poder rodar de novo depois da reversão.
            $table->foreign('treatment_id', 'treatment_photos_treatment_id_foreign')->references('id')->on('treatments')->restrictOnDelete();
        });

        Schema::table('patient_attachments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('patient_id');
        });

        Schema::table('patient_attachments', function (Blueprint $table) {
            $table->dropColumn('taken_on');
        });

        Schema::rename('patient_attachments', 'treatment_attachments');
    }
};
