<?php

namespace App\Http\Controllers;

use App\Http\Requests\TaskCommentRequest;
use App\Models\Task;
use App\Models\TaskComment;
use App\Support\BoardReturn;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class TaskCommentController extends Controller
{
    public function store(TaskCommentRequest $request, Task $task): RedirectResponse
    {
        $comment = new TaskComment($request->validated());
        $comment->user_id = $request->user()->getKey();
        $task->comments()->save($comment);

        return BoardReturn::toTask($task, 'comentarios')->with('success', 'Comentário publicado.');
    }

    public function destroy(Task $task, TaskComment $comment): RedirectResponse
    {
        Gate::authorize('deleteComment', [$task, $comment]);

        $comment->delete();

        return BoardReturn::toTask($task, 'comentarios')->with('success', 'Comentário removido.');
    }
}
