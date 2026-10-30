<?php

namespace App\Http\Controllers;

use App\Http\Requests\SubtaskRequest;
use App\Models\Subtask;
use App\Models\Task;
use App\Services\TaskHistory;
use App\Support\BoardReturn;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Subtarefas de uma tarefa. Concluir e reabrir entram no histórico, porque
 * mudam o progresso da tarefa.
 */
class SubtaskController extends Controller
{
    public function __construct(private readonly TaskHistory $history) {}

    public function store(SubtaskRequest $request, Task $task): RedirectResponse
    {
        DB::transaction(function () use ($request, $task): void {
            $subtask = new Subtask($request->validated());
            $subtask->position = (int) $task->subtasks()->max('position') + 1;
            $task->subtasks()->save($subtask);

            $this->history->log($task, $request->user(), 'subtask_added', ['title' => $subtask->title]);
        });

        return BoardReturn::toTask($task, 'subtarefas')->with('success', 'Subtarefa adicionada.');
    }

    public function update(SubtaskRequest $request, Task $task, Subtask $subtask): RedirectResponse
    {
        $subtask->update($request->validated());

        return BoardReturn::toTask($task, 'subtarefas')->with('success', 'Subtarefa atualizada.');
    }

    /**
     * Marca como concluída ou reabre.
     */
    public function toggle(Request $request, Task $task, Subtask $subtask): RedirectResponse
    {
        Gate::authorize('update', $task);

        DB::transaction(function () use ($request, $task, $subtask): void {
            $completing = ! $subtask->isCompleted();
            $subtask->completed_at = $completing ? now() : null;
            $subtask->save();

            $this->history->log($task, $request->user(), $completing ? 'subtask_completed' : 'subtask_reopened', ['title' => $subtask->title]);
        });

        return BoardReturn::toTask($task, 'subtarefas')->with('success', $subtask->isCompleted() ? 'Subtarefa concluída.' : 'Subtarefa reaberta.');
    }

    public function destroy(Request $request, Task $task, Subtask $subtask): RedirectResponse
    {
        Gate::authorize('update', $task);

        DB::transaction(function () use ($request, $task, $subtask): void {
            $subtask->delete();
            $task->subtasks()->where('position', '>', $subtask->position)->decrement('position');

            $this->history->log($task, $request->user(), 'subtask_removed', ['title' => $subtask->title]);
        });

        return BoardReturn::toTask($task, 'subtarefas')->with('success', 'Subtarefa removida.');
    }
}
