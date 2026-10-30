<?php

namespace App\Services;

use App\Models\Task;
use App\Models\TaskColumn;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Coluna e posição das tarefas no quadro. Toda mudança de lugar passa por aqui,
 * para a ordem dentro de cada coluna continuar sem buracos nem repetição.
 */
class TaskBoard
{
    public function __construct(private readonly TaskHistory $history) {}

    /**
     * Coloca a tarefa nova no fim da coluna.
     */
    public function append(Task $task, TaskColumn $column): void
    {
        $task->task_column_id = $column->getKey();
        $task->position = Task::query()->where('task_column_id', $column->getKey())->count();
    }

    /**
     * Move para a coluna e a posição (a partir de zero) escolhidas. As duas
     * colunas são renumeradas de 0 em diante, então a ordem fica sempre densa,
     * mesmo que algum registro antigo tenha ficado com buraco.
     */
    public function move(Task $task, TaskColumn $column, ?int $position, User $user): void
    {
        DB::transaction(function () use ($task, $column, $position, $user): void {
            $task = Task::query()->lockForUpdate()->findOrFail($task->getKey());
            $from = $task->column;

            $target = $this->orderedIds($column, $task);
            array_splice($target, $position === null ? count($target) : max(0, min($position, count($target))), 0, [$task->getKey()]);

            if (! $from->is($column)) {
                $this->renumber($from, $this->orderedIds($from, $task));
                $task->task_column_id = $column->getKey();
                $task->save();
                $this->history->log($task, $user, 'moved', ['from' => $from->name, 'to' => $column->name]);
            }

            $this->renumber($column, $target);
        });
    }

    /**
     * @return list<int>
     */
    private function orderedIds(TaskColumn $column, Task $except): array
    {
        return Task::query()->where('task_column_id', $column->getKey())->whereKeyNot($except->getKey())->orderBy('position')->orderBy('id')->pluck('id')->all();
    }

    /**
     * Grava a posição de cada tarefa pela ordem da lista, só nas que mudaram.
     *
     * @param  list<int>  $ids
     */
    private function renumber(TaskColumn $column, array $ids): void
    {
        $current = Task::query()->whereKey($ids)->pluck('position', 'id');

        foreach ($ids as $position => $id) {
            if ((int) $current[$id] !== $position) {
                Task::query()->whereKey($id)->update(['task_column_id' => $column->getKey(), 'position' => $position]);
            }
        }
    }
}
