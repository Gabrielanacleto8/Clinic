<?php

namespace App\Http\Controllers;

use App\Http\Requests\TaskLabelRequest;
use App\Models\Task;
use App\Models\TaskLabel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Etiquetas do quadro. Excluir uma etiqueta só a tira das tarefas.
 */
class TaskLabelController extends Controller
{
    public function index(): View
    {
        Gate::authorize('configure', Task::class);

        return view('tasks.labels', [
            'labels' => TaskLabel::query()->withCount('tasks')->orderBy('name')->get(),
            'colors' => TaskLabel::colorOptions(),
        ]);
    }

    public function store(TaskLabelRequest $request): RedirectResponse
    {
        TaskLabel::query()->create($request->validated());

        return redirect()->route('tasks.labels.index')->with('success', 'Etiqueta criada.');
    }

    public function update(TaskLabelRequest $request, TaskLabel $label): RedirectResponse
    {
        $label->update($request->validated());

        return redirect()->route('tasks.labels.index')->with('success', 'Etiqueta atualizada.');
    }

    public function destroy(TaskLabel $label): RedirectResponse
    {
        Gate::authorize('configure', Task::class);

        $label->delete();

        return redirect()->route('tasks.labels.index')->with('success', 'Etiqueta excluída.');
    }
}
