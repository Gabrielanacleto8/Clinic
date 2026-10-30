<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Subtarefa de uma tarefa, com responsável e prazo próprios. Pode ter um
 * checklist de atividades.
 */
class Subtask extends Model
{
    protected $fillable = ['title', 'description', 'assignee_id', 'due_on'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['due_on' => 'immutable_date', 'completed_at' => 'datetime'];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ChecklistItem::class)->orderBy('position');
    }

    public function isCompleted(): bool
    {
        return $this->completed_at !== null;
    }
}
