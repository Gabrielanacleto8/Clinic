<?php

namespace App\Services;

use App\Models\Task;
use App\Models\TaskActivity;
use App\Models\User;

/**
 * Histórico de alterações da tarefa. Os detalhes são nomes de campo, de
 * coluna, de subtarefa e de anexo; nunca o texto digitado.
 */
class TaskHistory
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function log(Task $task, ?User $user, string $action, array $details = []): TaskActivity
    {
        $activity = new TaskActivity;
        $activity->task_id = $task->getKey();
        $activity->user_id = $user?->getKey();
        $activity->action = $action;
        $activity->details = $details === [] ? null : $details;
        $activity->created_at = now();
        $activity->save();

        return $activity;
    }
}
