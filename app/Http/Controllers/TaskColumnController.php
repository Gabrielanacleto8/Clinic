<?php

namespace App\Http\Controllers;

use App\Enums\TaskColumnKind;
use App\Http\Requests\TaskColumnRequest;
use App\Models\Task;
use App\Models\TaskColumn;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Colunas do quadro: nome, tipo e ordem. Uma coluna só sai vazia.
 */
class TaskColumnController extends Controller
{
    public function index(): View
    {
        Gate::authorize('configure', Task::class);

        return view('tasks.columns', [
            'columns' => TaskColumn::query()->withCount('tasks')->orderBy('position')->get(),
            'kinds' => TaskColumnKind::options(),
        ]);
    }

    public function store(TaskColumnRequest $request): RedirectResponse
    {
        DB::transaction(function () use ($request): void {
            $column = new TaskColumn($request->validated());
            $this->place($column, (int) $request->validated('position'));
        });

        return redirect()->route('tasks.columns.index')->with('success', 'Coluna criada.');
    }

    public function update(TaskColumnRequest $request, TaskColumn $column): RedirectResponse
    {
        DB::transaction(function () use ($request, $column): void {
            $column->fill($request->safe()->except('position'));
            $this->place($column, (int) $request->validated('position'));
        });

        return redirect()->route('tasks.columns.index')->with('success', 'Coluna atualizada.');
    }

    public function destroy(TaskColumn $column): RedirectResponse
    {
        Gate::authorize('configure', Task::class);

        if ($column->tasks()->exists()) {
            return redirect()->route('tasks.columns.index')->withErrors(['column' => 'Mova as tarefas desta coluna para outra antes de excluí-la.']);
        }

        if (TaskColumn::query()->count() <= 1) {
            return redirect()->route('tasks.columns.index')->withErrors(['column' => 'O quadro precisa de ao menos uma coluna.']);
        }

        DB::transaction(function () use ($column): void {
            $column->delete();
            TaskColumn::query()->where('position', '>', $column->position)->decrement('position');
        });

        return redirect()->route('tasks.columns.index')->with('success', 'Coluna excluída.');
    }

    /**
     * Grava a coluna na ordem pedida, empurrando as demais e fechando o buraco que ela deixou.
     */
    private function place(TaskColumn $column, int $position): void
    {
        $others = TaskColumn::query()->whereKeyNot($column->getKey() ?? 0)->orderBy('position')->get();
        $position = min($position, $others->count() + 1);

        $column->position = $position;
        $column->save();

        $next = 1;

        foreach ($others as $other) {
            if ($next === $position) {
                $next++;
            }

            if ($other->position !== $next) {
                $other->update(['position' => $next]);
            }

            $next++;
        }
    }
}
