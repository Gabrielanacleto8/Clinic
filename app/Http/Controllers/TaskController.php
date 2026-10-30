<?php

namespace App\Http\Controllers;

use App\Enums\TaskPriority;
use App\Http\Requests\MoveTaskRequest;
use App\Http\Requests\TaskBoardFilterRequest;
use App\Http\Requests\TaskRequest;
use App\Models\Task;
use App\Models\TaskColumn;
use App\Models\TaskLabel;
use App\Models\User;
use App\Services\AttachmentVault;
use App\Services\TaskBoard;
use App\Services\TaskHistory;
use App\Support\BoardReturn;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Quadro de tarefas: colunas com as tarefas, filtros e o painel da tarefa
 * aberta (?tarefa=ID), tudo numa tela só.
 */
class TaskController extends Controller
{
    public function __construct(
        private readonly TaskBoard $board,
        private readonly TaskHistory $history,
    ) {}

    public function index(TaskBoardFilterRequest $request): View
    {
        $filters = $request->filters();
        $user = $request->user();
        $columns = TaskColumn::query()->orderBy('position')->get();

        $tasks = Task::query()
            ->with(['assignee:id,name', 'labels'])
            ->withCount(['subtasks', 'subtasks as completed_subtasks_count' => fn (Builder $done) => $done->whereNotNull('completed_at'), 'comments', 'attachments'])
            ->when($filters['q'] !== '', fn (Builder $query) => $query->where('title', 'like', '%'.$filters['q'].'%'))
            ->when($filters['responsavel'], fn (Builder $query, int $id) => $query->where('assignee_id', $id))
            ->when($filters['prioridade'], fn (Builder $query, string $priority) => $query->where('priority', $priority))
            ->when($filters['etiqueta'], fn (Builder $query, int $id) => $query->whereHas('labels', fn (Builder $label) => $label->whereKey($id)))
            ->when($filters['minhas'], fn (Builder $query) => $query->ownedBy($user))
            ->orderBy('position')
            ->get()
            // A coluna de cada cartão vem da lista já carregada, sem consulta por tarefa.
            ->each(fn (Task $task) => $task->setRelation('column', $columns->firstWhere('id', $task->task_column_id)))
            ->groupBy('task_column_id');

        $open = $request->openTaskId() !== null ? $this->openTask($request->openTaskId()) : null;

        return view('tasks.index', [
            'columns' => $columns,
            'tasks' => $tasks,
            'filters' => $filters,
            'open' => $open,
            'openTab' => $request->openTab(),
            'users' => User::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'labels' => TaskLabel::query()->orderBy('name')->get(),
            'priorities' => TaskPriority::options(),
        ]);
    }

    public function store(TaskRequest $request): RedirectResponse
    {
        $task = DB::transaction(function () use ($request): Task {
            $task = new Task($request->safe()->except(['task_column_id', 'labels']));
            $task->created_by = $request->user()->getKey();
            $this->board->append($task, TaskColumn::query()->findOrFail((int) $request->validated('task_column_id')));
            $task->save();
            $task->labels()->sync($request->labelIds());

            $this->history->log($task, $request->user(), 'created');

            return $task;
        });

        return BoardReturn::toTask($task)->with('success', 'Tarefa criada.');
    }

    public function update(TaskRequest $request, Task $task): RedirectResponse
    {
        DB::transaction(function () use ($request, $task): void {
            $task->fill($request->safe()->except(['task_column_id', 'labels']));
            $changed = array_keys($task->getDirty());
            $task->save();

            $before = $task->labels()->pluck('task_labels.id')->sort()->values()->all();
            $task->labels()->sync($request->labelIds());

            if ($before !== collect($request->labelIds())->unique()->sort()->values()->all()) {
                $changed[] = 'labels';
            }

            if ($changed !== []) {
                $this->history->log($task, $request->user(), 'updated', ['fields' => $this->fieldLabels($changed)]);
            }

            $column = TaskColumn::query()->findOrFail((int) $request->validated('task_column_id'));

            if (! $column->is($task->column)) {
                $this->board->move($task, $column, null, $request->user());
            }
        });

        return BoardReturn::toTask($task)->with('success', 'Tarefa atualizada.');
    }

    /**
     * Arrastar no quadro (JSON) ou escolher a coluna no painel.
     */
    public function move(MoveTaskRequest $request, Task $task): Response|RedirectResponse
    {
        $this->board->move($task, TaskColumn::query()->findOrFail((int) $request->validated('task_column_id')), (int) $request->validated('position'), $request->user());

        return $request->expectsJson()
            ? response()->noContent()
            : BoardReturn::toTask($task)->with('success', 'Tarefa movida.');
    }

    public function destroy(Task $task, AttachmentVault $vault): RedirectResponse
    {
        Gate::authorize('delete', $task);

        $paths = $task->attachments()->pluck('path')->all();

        DB::transaction(fn () => $task->delete());

        if ($paths !== []) {
            $vault->delete(...$paths);
        }

        return BoardReturn::toTask(null)->with('success', 'Tarefa excluída.');
    }

    private function openTask(int $id): Task
    {
        return Task::query()
            ->with(['column', 'creator:id,name', 'assignee:id,name', 'labels', 'subtasks.assignee:id,name', 'subtasks.items', 'comments.user:id,name', 'activities.user:id,name', 'attachments.uploader:id,name'])
            ->findOrFail($id);
    }

    /**
     * @param  list<string>  $fields
     * @return list<string>
     */
    private function fieldLabels(array $fields): array
    {
        $labels = [
            'title' => 'título', 'description' => 'descrição', 'priority' => 'prioridade', 'assignee_id' => 'responsável',
            'starts_on' => 'data de início', 'due_on' => 'prazo', 'labels' => 'etiquetas',
        ];

        return array_values(array_filter(array_map(fn (string $field) => $labels[$field] ?? null, $fields)));
    }
}
