<?php

namespace App\Http\Controllers;

use App\Http\Requests\ChecklistItemRequest;
use App\Models\ChecklistItem;
use App\Models\Subtask;
use App\Models\Task;
use App\Support\BoardReturn;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Checklist de atividades de uma subtarefa.
 */
class ChecklistItemController extends Controller
{
    public function store(ChecklistItemRequest $request, Task $task, Subtask $subtask): RedirectResponse
    {
        $item = new ChecklistItem($request->validated());
        $item->position = (int) $subtask->items()->max('position') + 1;
        $subtask->items()->save($item);

        return BoardReturn::toTask($task, 'subtarefas')->with('success', 'Atividade adicionada.');
    }

    public function toggle(Request $request, Task $task, Subtask $subtask, ChecklistItem $item): RedirectResponse
    {
        Gate::authorize('update', $task);

        $item->done_at = $item->isDone() ? null : now();
        $item->save();

        return BoardReturn::toTask($task, 'subtarefas');
    }

    public function destroy(Task $task, Subtask $subtask, ChecklistItem $item): RedirectResponse
    {
        Gate::authorize('update', $task);

        $item->delete();
        $subtask->items()->where('position', '>', $item->position)->decrement('position');

        return BoardReturn::toTask($task, 'subtarefas')->with('success', 'Atividade removida.');
    }
}
