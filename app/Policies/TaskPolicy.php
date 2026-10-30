<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\User;

/**
 * Quem vê o quadro cria tarefas e cuida das próprias: as que criou ou que lhe
 * foram atribuídas. Mexer nas dos outros e configurar o quadro exige gerenciar.
 */
class TaskPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::TasksView);
    }

    public function view(User $user, Task $task): bool
    {
        return $user->hasPermission(Permission::TasksView);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permission::TasksView);
    }

    /**
     * Editar, mover, e cuidar das subtarefas, do checklist e dos anexos.
     */
    public function update(User $user, Task $task): bool
    {
        return $user->hasPermission(Permission::TasksManage)
            || ($user->hasPermission(Permission::TasksView) && $task->isOwnedBy($user));
    }

    public function delete(User $user, Task $task): bool
    {
        return $user->hasPermission(Permission::TasksManage)
            || ($user->hasPermission(Permission::TasksView) && $task->created_by === $user->getKey());
    }

    public function comment(User $user, Task $task): bool
    {
        return $user->hasPermission(Permission::TasksView);
    }

    public function deleteComment(User $user, Task $task, TaskComment $comment): bool
    {
        return $user->hasPermission(Permission::TasksManage) || $comment->user_id === $user->getKey();
    }

    /**
     * Colunas e etiquetas do quadro.
     */
    public function configure(User $user): bool
    {
        return $user->hasPermission(Permission::TasksManage);
    }
}
