<?php

namespace Tests\Feature;

use App\Enums\Permission;
use App\Enums\UserRole;
use App\Models\Subtask;
use App\Models\Task;
use App\Models\TaskActivity;
use App\Models\TaskAttachment;
use App\Models\TaskColumn;
use App\Models\TaskLabel;
use App\Models\User;
use App\Services\RolePermissions;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TaskBoardTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-30 10:00', 'America/Sao_Paulo'));
        Storage::fake('patient_photos');
        $this->admin = User::factory()->admin()->create(['name' => 'Ana Admin']);
        $this->member = User::factory()->create(['name' => 'Rita Recepção']);
    }

    private function column(string $name): TaskColumn
    {
        return TaskColumn::query()->where('name', $name)->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'title' => 'Conferir validade do estoque',
            'description' => 'Separar os lotes que vencem este mês.',
            'task_column_id' => $this->column('Não iniciadas')->id,
            'priority' => 'high',
            'assignee_id' => $this->member->id,
            'starts_on' => '2026-10-30',
            'due_on' => '2026-11-05',
            ...$overrides,
        ];
    }

    private function task(User $creator, array $overrides = []): Task
    {
        $task = new Task(['title' => 'Tarefa', 'priority' => 'medium', ...array_diff_key($overrides, ['task_column_id' => true])]);
        $task->created_by = $creator->id;
        $task->task_column_id = $overrides['task_column_id'] ?? $this->column('Não iniciadas')->id;
        $task->position = Task::query()->where('task_column_id', $task->task_column_id)->count();
        $task->save();

        return $task;
    }

    public function test_the_default_board_has_five_columns_and_the_team_sees_it(): void
    {
        $this->assertSame(['Não iniciadas', 'Em andamento', 'Em revisão', 'Concluídas', 'Canceladas'], TaskColumn::query()->orderBy('position')->pluck('name')->all());

        $this->get('/tarefas')->assertRedirect('/login');

        // Perfis padrão veem o quadro; só o administrador configura.
        $this->actingAs($this->member)->get('/tarefas')->assertOk()->assertSee('Nova tarefa')->assertDontSee('href="'.route('tasks.columns.index').'"', false);
        $this->get('/tarefas/colunas')->assertForbidden();
        $this->get('/tarefas/etiquetas')->assertForbidden();
        $this->actingAs($this->admin)->get('/tarefas/colunas')->assertOk()->assertSee('Em revisão');

        // Sem a permissão, nem o menu nem o quadro.
        app(RolePermissions::class)->sync(UserRole::Receptionist, [Permission::PatientsView]);
        $this->actingAs(User::factory()->create())->get('/tarefas')->assertForbidden();
        $this->get('/pacientes')->assertDontSee('href="'.route('tasks.index').'"', false);
    }

    public function test_a_task_is_created_in_the_chosen_column_and_opens_in_the_panel(): void
    {
        $label = TaskLabel::query()->create(['name' => 'Estoque', 'color' => 'amber']);

        $this->actingAs($this->member)->from('/tarefas?minhas=1')
            ->post('/tarefas', [...$this->payload(), '_dialog' => 'task-create', 'labels' => [$label->id]])
            ->assertRedirect('/tarefas?minhas=1&tarefa=1')->assertSessionHas('success', 'Tarefa criada.');

        $task = Task::query()->sole();
        $this->assertSame($this->member->id, $task->created_by);
        $this->assertSame(0, $task->position);
        $this->assertSame(['Estoque'], $task->labels->pluck('name')->all());
        $this->assertSame('created', $task->activities()->sole()->action);

        $html = $this->get('/tarefas?tarefa='.$task->id)->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<dialog id="task-panel"[^>]*data-dialog-autoshow/', $html);
        $this->assertStringContainsString('Conferir validade do estoque', $html);
        $this->assertStringContainsString('Rita Recepção', $html);
        $this->assertStringContainsString('value="2026-11-05"', $html);
        $this->assertStringContainsString('data-task-card="'.$task->id.'"', $html);

        $this->get('/tarefas?tarefa=999')->assertNotFound();
    }

    public function test_task_data_is_validated_and_the_dialog_reopens(): void
    {
        $html = $this->actingAs($this->admin)->from('/tarefas')->followingRedirects()
            ->post('/tarefas', [...$this->payload(['title' => 'A', 'due_on' => '2026-10-01', 'priority' => 'alta']), '_dialog' => 'task-create'])
            ->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<dialog id="task-create"[^>]*data-dialog-autoshow/', $html);
        $this->assertStringContainsString('O prazo não pode ser antes da data de início.', $html);
        $this->assertSame(0, Task::count());
    }

    public function test_dragging_moves_between_columns_and_reorders_keeping_positions_dense(): void
    {
        $first = $this->task($this->admin, ['title' => 'Primeira']);
        $second = $this->task($this->admin, ['title' => 'Segunda']);
        $third = $this->task($this->admin, ['title' => 'Terceira']);
        $doing = $this->column('Em andamento');

        $this->actingAs($this->admin)->postJson("/tarefas/{$second->id}/mover", ['task_column_id' => $doing->id, 'position' => 0])->assertNoContent();

        $this->assertSame([$first->id => 0, $third->id => 1], Task::query()->where('task_column_id', $first->task_column_id)->orderBy('position')->pluck('position', 'id')->all());
        $this->assertSame(0, $second->refresh()->position);
        $this->assertSame('moved', $second->activities()->latest('id')->sole()->action);
        $this->assertEquals(['from' => 'Não iniciadas', 'to' => 'Em andamento'], $second->activities()->latest('id')->sole()->details);

        // Reordenar dentro da coluna não entra no histórico.
        $this->postJson("/tarefas/{$third->id}/mover", ['task_column_id' => $first->task_column_id, 'position' => 0])->assertNoContent();
        $this->assertSame([$third->id => 0, $first->id => 1], Task::query()->where('task_column_id', $first->task_column_id)->orderBy('position')->pluck('position', 'id')->all());
        $this->assertSame(0, $third->activities()->where('action', 'moved')->count());

        // Pelo painel, trocar a coluna na edição também move.
        $this->from('/tarefas')->put("/tarefas/{$first->id}", $this->payload(['title' => 'Primeira', 'task_column_id' => $doing->id, 'assignee_id' => null]))->assertRedirect('/tarefas?tarefa='.$first->id);
        $this->assertSame($doing->id, $first->refresh()->task_column_id);
        $this->assertSame(1, $first->position);
    }

    public function test_only_the_creator_the_assignee_or_a_manager_change_a_task(): void
    {
        $other = User::factory()->create();
        $task = $this->task($this->admin, ['assignee_id' => $this->member->id]);

        // Atribuída à recepção: ela edita e move, mas não exclui (não criou).
        $this->actingAs($this->member)->put("/tarefas/{$task->id}", $this->payload(['title' => 'Editada pela responsável']))->assertRedirect();
        $this->assertSame('Editada pela responsável', $task->refresh()->title);
        $this->postJson("/tarefas/{$task->id}/mover", ['task_column_id' => $this->column('Concluídas')->id, 'position' => 0])->assertNoContent();
        $this->delete("/tarefas/{$task->id}")->assertForbidden();

        // Outra pessoa só comenta.
        $this->actingAs($other)->put("/tarefas/{$task->id}", $this->payload())->assertForbidden();
        $this->postJson("/tarefas/{$task->id}/mover", ['task_column_id' => $this->column('Em revisão')->id, 'position' => 0])->assertForbidden();
        $this->post("/tarefas/{$task->id}/subtarefas", ['title' => 'Intrusa'])->assertForbidden();
        $this->post("/tarefas/{$task->id}/anexos", ['attachment' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')])->assertForbidden();
        $this->post("/tarefas/{$task->id}/comentarios", ['body' => 'Posso ajudar?'])->assertRedirect();
        $this->assertSame(1, $task->comments()->count());

        // Quem gerencia faz tudo, inclusive excluir.
        app(RolePermissions::class)->sync(UserRole::Professional, [Permission::TasksView, Permission::TasksManage]);
        $manager = User::factory()->professional()->create();
        $this->actingAs($manager)->put("/tarefas/{$task->id}", $this->payload(['title' => 'Editada pela gestão']))->assertRedirect();
        $this->get('/tarefas/colunas')->assertOk();
        $this->delete("/tarefas/{$task->id}")->assertRedirect('/tarefas');
        $this->assertSame(0, Task::count());
    }

    public function test_subtasks_and_checklist_drive_the_progress_bar(): void
    {
        $task = $this->task($this->admin);
        $this->actingAs($this->admin);

        $this->from('/tarefas?tarefa='.$task->id)->post("/tarefas/{$task->id}/subtarefas", ['_dialog' => 'task-subtask', 'title' => 'Separar os lotes', 'assignee_id' => $this->member->id, 'due_on' => '2026-11-01'])
            ->assertRedirect('/tarefas?tarefa='.$task->id.'&aba=subtarefas');
        $this->post("/tarefas/{$task->id}/subtarefas", ['title' => 'Avisar a equipe']);
        $this->post("/tarefas/{$task->id}/subtarefas", ['title' => 'T'])->assertSessionHasErrors('title');

        [$first, $second] = $task->subtasks()->orderBy('position')->get();
        $this->assertSame([1, 2], [$first->position, $second->position]);

        $this->post("/tarefas/{$task->id}/subtarefas/{$first->id}/itens", ['title' => 'Lote A'])->assertRedirect();
        $this->post("/tarefas/{$task->id}/subtarefas/{$first->id}/itens", ['title' => 'Lote B'])->assertRedirect();
        $item = $first->items()->first();
        $this->post("/tarefas/{$task->id}/subtarefas/{$first->id}/itens/{$item->id}/concluir")->assertRedirect();
        $this->assertNotNull($item->refresh()->done_at);

        $html = $this->get('/tarefas')->getContent();
        $this->assertStringContainsString('0/2', $html);

        $this->post("/tarefas/{$task->id}/subtarefas/{$first->id}/concluir")->assertRedirect();
        $this->assertNotNull($first->refresh()->completed_at);
        $this->assertStringContainsString('value="50"', $this->get('/tarefas')->getContent());
        $this->assertStringContainsString('1 de 2 subtarefas', $this->get('/tarefas?tarefa='.$task->id)->getContent());

        $this->post("/tarefas/{$task->id}/subtarefas/{$first->id}/concluir")->assertRedirect();
        $this->assertNull($first->refresh()->completed_at);
        $this->assertSame(['subtask_reopened', 'subtask_completed', 'subtask_added', 'subtask_added'], $task->activities()->limit(4)->pluck('action')->all());

        // A subtarefa de outra tarefa não é alcançada por esta.
        $otherTask = $this->task($this->admin);
        $this->post("/tarefas/{$otherTask->id}/subtarefas/{$first->id}/concluir")->assertNotFound();

        $this->delete("/tarefas/{$task->id}/subtarefas/{$first->id}")->assertRedirect();
        $this->assertSame(1, $second->refresh()->position);
        $this->assertSame(0, Subtask::query()->whereKey($first->id)->count());
    }

    public function test_attachments_are_encrypted_checked_by_signature_and_downloaded_by_who_sees_the_board(): void
    {
        $task = $this->task($this->admin);
        $this->actingAs($this->admin);

        $this->post("/tarefas/{$task->id}/anexos", ['attachment' => UploadedFile::fake()->createWithContent('nota.pdf', 'isto não é um pdf')])->assertSessionHasErrors('attachment');
        $this->post("/tarefas/{$task->id}/anexos", ['attachment' => UploadedFile::fake()->createWithContent('script.exe', '%PDF-1.4 x')])->assertSessionHasErrors('attachment');

        $this->from('/tarefas?tarefa='.$task->id)
            ->post("/tarefas/{$task->id}/anexos", ['attachment' => UploadedFile::fake()->createWithContent("../nota\x00 fiscal.pdf", '%PDF-1.4 conteúdo'), 'name' => ''])
            ->assertRedirect('/tarefas?tarefa='.$task->id.'&aba=anexos')->assertSessionHas('success', 'Anexo adicionado.');

        $attachment = TaskAttachment::query()->sole();
        $this->assertSame('pdf', $attachment->extension);
        $this->assertStringStartsWith('tasks/', $attachment->path);
        $this->assertStringNotContainsString('nota', $attachment->path);
        $this->assertStringNotContainsString('%PDF', Storage::disk('patient_photos')->get($attachment->path));

        $this->actingAs($this->member)->get("/tarefas/{$task->id}/anexos/{$attachment->id}")->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', 'attachment; filename="anexo-'.$attachment->id.'.pdf"');
        $this->assertSame('%PDF-1.4 conteúdo', $this->get("/tarefas/{$task->id}/anexos/{$attachment->id}")->getContent());

        $otherTask = $this->task($this->admin);
        $this->get("/tarefas/{$otherTask->id}/anexos/{$attachment->id}")->assertNotFound();
        $this->delete("/tarefas/{$task->id}/anexos/{$attachment->id}")->assertForbidden();

        $this->actingAs($this->admin)->delete("/tarefas/{$task->id}/anexos/{$attachment->id}")->assertRedirect();
        $this->assertFalse(Storage::disk('patient_photos')->exists($attachment->path));
        $this->assertSame(['attachment_removed', 'attachment_added'], $task->activities()->limit(2)->pluck('action')->all());
    }

    public function test_columns_and_labels_are_configured_by_who_manages(): void
    {
        $this->actingAs($this->admin);

        $this->post('/tarefas/colunas', ['name' => 'Aguardando material', 'kind' => 'open', 'position' => 2])->assertRedirect('/tarefas/colunas');
        $this->assertSame(['Não iniciadas', 'Aguardando material', 'Em andamento', 'Em revisão', 'Concluídas', 'Canceladas'], TaskColumn::query()->orderBy('position')->pluck('name')->all());
        $this->assertSame([1, 2, 3, 4, 5, 6], TaskColumn::query()->orderBy('position')->pluck('position')->all());

        $waiting = $this->column('Aguardando material');
        $this->put("/tarefas/colunas/{$waiting->id}", ['name' => 'Aguardando', 'kind' => 'open', 'position' => 6])->assertRedirect();
        $this->assertSame(['Não iniciadas', 'Em andamento', 'Em revisão', 'Concluídas', 'Canceladas', 'Aguardando'], TaskColumn::query()->orderBy('position')->pluck('name')->all());

        // Coluna com tarefa não sai.
        $task = $this->task($this->admin, ['task_column_id' => $waiting->id]);
        $this->delete("/tarefas/colunas/{$waiting->id}")->assertSessionHasErrors('column');
        $task->delete();
        $this->delete("/tarefas/colunas/{$waiting->id}")->assertRedirect()->assertSessionHas('success');
        $this->assertSame([1, 2, 3, 4, 5], TaskColumn::query()->orderBy('position')->pluck('position')->all());

        $this->post('/tarefas/etiquetas', ['name' => 'Urgente', 'color' => 'red'])->assertRedirect('/tarefas/etiquetas');
        $this->post('/tarefas/etiquetas', ['name' => 'Urgente', 'color' => 'sky'])->assertSessionHasErrors('name');
        $this->post('/tarefas/etiquetas', ['name' => 'Rosa', 'color' => 'pink'])->assertSessionHasErrors('color');
        $label = TaskLabel::query()->sole();
        $this->put("/tarefas/etiquetas/{$label->id}", ['name' => 'Prioritária', 'color' => 'violet'])->assertRedirect();
        $this->assertSame('Prioritária', $label->refresh()->name);

        $this->actingAs($this->member);
        $this->post('/tarefas/colunas', ['name' => 'X', 'kind' => 'open', 'position' => 1])->assertForbidden();
        $this->delete("/tarefas/etiquetas/{$label->id}")->assertForbidden();
        $this->assertSame(1, TaskLabel::count());
    }

    public function test_board_filters_and_comments(): void
    {
        $label = TaskLabel::query()->create(['name' => 'Compras', 'color' => 'sky']);
        $mine = $this->task($this->member, ['title' => 'Minha tarefa', 'priority' => 'urgent']);
        $mine->labels()->attach($label);
        $this->task($this->admin, ['title' => 'Tarefa da Ana', 'assignee_id' => $this->admin->id]);

        $this->actingAs($this->member);
        $this->get('/tarefas')->assertSee('Minha tarefa')->assertSee('Tarefa da Ana');
        $this->get('/tarefas?minhas=1')->assertSee('Minha tarefa')->assertDontSee('Tarefa da Ana');
        $this->get('/tarefas?q=Ana')->assertDontSee('Minha tarefa')->assertSee('Tarefa da Ana');
        $this->get('/tarefas?prioridade=urgent')->assertSee('Minha tarefa')->assertDontSee('Tarefa da Ana');
        $this->get('/tarefas?etiqueta='.$label->id)->assertSee('Minha tarefa')->assertDontSee('Tarefa da Ana');
        $this->get('/tarefas?responsavel='.$this->admin->id)->assertDontSee('Minha tarefa')->assertSee('Tarefa da Ana');
        $this->get('/tarefas?prioridade=x')->assertSessionHasErrors('prioridade');

        $this->from('/tarefas?tarefa='.$mine->id)->post("/tarefas/{$mine->id}/comentarios", ['_dialog' => 'task-comment', 'body' => '<b>Feito</b> hoje'])->assertRedirect('/tarefas?tarefa='.$mine->id.'&aba=comentarios');
        $this->get('/tarefas?tarefa='.$mine->id)->assertSee('&lt;b&gt;Feito&lt;/b&gt; hoje', false)->assertSee('Rita Recepção');
        $comment = $mine->comments()->sole();

        $this->actingAs(User::factory()->create())->delete("/tarefas/{$mine->id}/comentarios/{$comment->id}")->assertForbidden();
        $this->actingAs($this->member)->delete("/tarefas/{$mine->id}/comentarios/{$comment->id}")->assertRedirect();
        $this->assertSame(0, $mine->comments()->count());
    }

    public function test_the_board_runs_a_fixed_number_of_queries(): void
    {
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($this->admin)->get('/tarefas')->assertOk();

            return count(DB::getQueryLog());
        };

        $label = TaskLabel::query()->create(['name' => 'Compras', 'color' => 'sky']);
        // Com uma tarefa, os carregamentos antecipados já rodam; mais tarefas não acrescentam consulta.
        $this->task($this->admin, ['assignee_id' => $this->member->id])->labels()->attach($label);
        $empty = $count();

        foreach (range(1, 6) as $i) {
            $task = $this->task($this->admin, ['title' => "Tarefa {$i}", 'assignee_id' => $this->member->id, 'task_column_id' => TaskColumn::query()->inRandomOrder()->first()->id]);
            $task->labels()->attach($label);
            $subtask = new Subtask(['title' => 'Sub']);
            $subtask->position = 1;
            $subtask->completed_at = now();
            $task->subtasks()->save($subtask);
        }

        $this->assertSame($empty, $count());
        $this->assertSame(0, TaskActivity::count());
    }
}
