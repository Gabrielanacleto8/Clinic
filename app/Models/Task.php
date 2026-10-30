<?php

namespace App\Models;

use App\Enums\TaskColumnKind;
use App\Enums\TaskPriority;
use App\Support\ClinicTime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tarefa da equipe no quadro. A coluna e a posição mudam só pelo TaskBoard;
 * o histórico é gravado pelo TaskHistory.
 */
class Task extends Model
{
    protected $fillable = ['title', 'description', 'priority', 'assignee_id', 'starts_on', 'due_on'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'priority' => TaskPriority::class,
            'starts_on' => 'immutable_date',
            'due_on' => 'immutable_date',
        ];
    }

    public function column(): BelongsTo
    {
        return $this->belongsTo(TaskColumn::class, 'task_column_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function labels(): BelongsToMany
    {
        return $this->belongsToMany(TaskLabel::class, 'task_label')->orderBy('name');
    }

    public function subtasks(): HasMany
    {
        return $this->hasMany(Subtask::class)->orderBy('position');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(TaskComment::class)->orderBy('created_at');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(TaskActivity::class)->orderByDesc('created_at')->orderByDesc('id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(TaskAttachment::class)->orderBy('created_at');
    }

    /**
     * Tarefas que o usuário criou ou que foram atribuídas a ele.
     *
     * @param  Builder<Task>  $query
     */
    public function scopeOwnedBy(Builder $query, User $user): void
    {
        $query->where(fn (Builder $own) => $own->where('created_by', $user->getKey())->orWhere('assignee_id', $user->getKey()));
    }

    public function isOwnedBy(User $user): bool
    {
        return $this->created_by === $user->getKey() || $this->assignee_id === $user->getKey();
    }

    public function isOpen(): bool
    {
        return $this->column->kind === TaskColumnKind::Open;
    }

    public function isOverdue(): bool
    {
        return $this->due_on !== null && $this->isOpen() && $this->due_on->format('Y-m-d') < ClinicTime::today();
    }

    /**
     * Subtarefas concluídas sobre o total, em %. Usa as contagens carregadas
     * com withCount ou, se houver, as subtarefas já carregadas.
     */
    public function progress(): ?int
    {
        $total = $this->relationLoaded('subtasks') ? $this->subtasks->count() : (int) ($this->subtasks_count ?? 0);

        if ($total === 0) {
            return null;
        }

        $done = $this->relationLoaded('subtasks')
            ? $this->subtasks->whereNotNull('completed_at')->count()
            : (int) ($this->completed_subtasks_count ?? 0);

        return (int) round($done / $total * 100);
    }
}
