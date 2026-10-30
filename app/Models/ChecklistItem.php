<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Atividade do checklist de uma subtarefa.
 */
class ChecklistItem extends Model
{
    protected $fillable = ['title'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['done_at' => 'datetime'];
    }

    public function subtask(): BelongsTo
    {
        return $this->belongsTo(Subtask::class);
    }

    public function isDone(): bool
    {
        return $this->done_at !== null;
    }
}
