<?php

use App\Enums\Permission;
use App\Enums\UserRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Quadro de tarefas da equipe (estilo Planner). As colunas do quadro são
 * configuráveis: cada uma tem um tipo (em aberto, concluída ou cancelada)
 * que diz o que a posição da tarefa significa.
 *
 * Nada derivado é gravado: o progresso da tarefa é a proporção de subtarefas
 * concluídas e a quantidade por coluna é contada ao montar o quadro.
 * "position" é a ordem escolhida pelo usuário dentro da coluna, que não
 * deriva de nada. As tarefas não guardam dado de paciente nem de saúde.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_columns', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60);
            $table->string('kind', 12);
            $table->unsignedSmallInteger('position');
            $table->timestamps();
        });

        Schema::create('task_labels', function (Blueprint $table) {
            $table->id();
            $table->string('name', 40)->unique();
            $table->string('color', 12);
            $table->timestamps();
        });

        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_column_id')->constrained()->restrictOnDelete();
            $table->string('title', 150);
            $table->text('description')->nullable();
            $table->string('priority', 10);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('starts_on')->nullable();
            $table->date('due_on')->nullable();
            $table->unsignedInteger('position');
            $table->timestamps();

            $table->index(['task_column_id', 'position']);
            $table->index('assignee_id');
            $table->index('due_on');
        });

        Schema::create('task_label', function (Blueprint $table) {
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('task_label_id')->constrained()->cascadeOnDelete();
            $table->primary(['task_id', 'task_label_id']);
        });

        Schema::create('subtasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->string('title', 150);
            $table->text('description')->nullable();
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('due_on')->nullable();
            $table->unsignedInteger('position');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['task_id', 'position']);
        });

        Schema::create('checklist_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subtask_id')->constrained()->cascadeOnDelete();
            $table->string('title', 150);
            $table->unsignedInteger('position');
            $table->timestamp('done_at')->nullable();
            $table->timestamps();

            $table->index(['subtask_id', 'position']);
        });

        Schema::create('task_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('body');
            $table->timestamps();
        });

        // Histórico da tarefa: quem fez o quê. Os detalhes são nomes de campo e
        // de coluna, nunca o conteúdo digitado.
        Schema::create('task_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 30);
            $table->json('details')->nullable();
            $table->timestamp('created_at');

            $table->index(['task_id', 'created_at']);
        });

        // O arquivo fica criptografado no disco privado, pelo AttachmentVault; aqui, só os metadados.
        Schema::create('task_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 100);
            $table->string('extension', 5);
            $table->unsignedInteger('size');
            $table->string('path', 120);
            $table->timestamps();
        });

        $now = now();

        DB::table('task_columns')->insert([
            ['name' => 'Não iniciadas', 'kind' => 'open', 'position' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Em andamento', 'kind' => 'open', 'position' => 2, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Em revisão', 'kind' => 'open', 'position' => 3, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Concluídas', 'kind' => 'done', 'position' => 4, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Canceladas', 'kind' => 'cancelled', 'position' => 5, 'created_at' => $now, 'updated_at' => $now],
        ]);

        // Toda a equipe vê o quadro e cuida das próprias tarefas; editar as dos
        // outros e configurar o quadro ficam com o administrador até ele conceder.
        foreach ([UserRole::Professional, UserRole::Receptionist] as $role) {
            $row = ['role' => $role->value, 'permission' => Permission::TasksView->value];
            DB::table('role_permissions')->updateOrInsert($row, $row);
        }
    }

    public function down(): void
    {
        DB::table('role_permissions')->whereIn('permission', [Permission::TasksView->value, Permission::TasksManage->value])->delete();

        Schema::dropIfExists('task_attachments');
        Schema::dropIfExists('task_activities');
        Schema::dropIfExists('task_comments');
        Schema::dropIfExists('checklist_items');
        Schema::dropIfExists('subtasks');
        Schema::dropIfExists('task_label');
        Schema::dropIfExists('tasks');
        Schema::dropIfExists('task_labels');
        Schema::dropIfExists('task_columns');
    }
};
