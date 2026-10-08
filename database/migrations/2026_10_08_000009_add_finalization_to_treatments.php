<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('treatments', function (Blueprint $table) {
            // Rascunho até ser finalizado; finalizado, vira histórico permanente.
            $table->string('status', 12)->default('draft')->after('procedure_id')->index();
            $table->dateTime('finalized_at')->nullable()->after('status');
            // Avaliação clínica, criptografada na aplicação como os demais textos.
            $table->text('assessment')->nullable()->after('treated_areas');
        });

        // Produto, lote e validade eram digitados à mão em cada atendimento.
        // Passam a vir do estoque, por referência ao lote. A troca só é segura
        // sem registros, porque texto livre não se converte em lote sozinho.
        if (DB::table('treatment_products')->exists()) {
            throw new RuntimeException('Há produtos digitados em atendimentos. Cadastre-os no estoque e vincule os registros antes de migrar.');
        }

        Schema::table('treatment_products', function (Blueprint $table) {
            $table->dropIndex(['batch']);
            $table->dropColumn(['name', 'batch', 'expires_on', 'quantity']);
        });

        Schema::table('treatment_products', function (Blueprint $table) {
            $table->foreignId('product_batch_id')->after('treatment_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 12, 3)->after('product_batch_id');

            // O mesmo lote aparece uma vez só por atendimento.
            $table->unique(['treatment_id', 'product_batch_id']);
        });

        // Fotos e documentos têm quase as mesmas colunas: uma tabela só, com o
        // tipo do anexo. As colunas próprias de imagem ficam opcionais.
        Schema::rename('treatment_photos', 'treatment_attachments');

        Schema::table('treatment_attachments', function (Blueprint $table) {
            $table->string('type', 10)->default('photo')->after('treatment_id');
            $table->string('stage', 20)->nullable()->change();
            $table->string('thumbnail_path')->nullable()->change();
            $table->unsignedInteger('width')->nullable()->change();
            $table->unsignedInteger('height')->nullable()->change();
        });

        // Evoluções e adendos: só recebem inserções. É por aqui que um
        // atendimento finalizado ganha informação nova sem ser alterado.
        Schema::create('treatment_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('treatment_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('body');
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('treatment_notes');

        Schema::table('treatment_attachments', function (Blueprint $table) {
            $table->dropColumn('type');
        });

        Schema::rename('treatment_attachments', 'treatment_photos');

        Schema::table('treatment_products', function (Blueprint $table) {
            $table->dropUnique(['treatment_id', 'product_batch_id']);
            $table->dropConstrainedForeignId('product_batch_id');
            $table->dropColumn('quantity');
        });

        Schema::table('treatment_products', function (Blueprint $table) {
            $table->string('name', 150)->default('');
            $table->string('batch', 60)->nullable()->index();
            $table->date('expires_on')->nullable();
            $table->string('quantity', 60)->nullable();
        });

        Schema::table('treatments', function (Blueprint $table) {
            $table->dropColumn(['status', 'finalized_at', 'assessment']);
        });
    }
};
