<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uma linha do histórico da tarefa. Gravada só pelo TaskHistory.
 */
class TaskActivity extends Model
{
    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['details' => 'array', 'created_at' => 'datetime'];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Frase do histórico, sem o nome de quem fez (a tela o mostra ao lado).
     */
    public function describe(): string
    {
        $details = $this->details ?? [];
        $quoted = fn (string $key) => '"'.($details[$key] ?? '').'"';

        return match ($this->action) {
            'created' => 'criou a tarefa',
            'updated' => 'alterou '.implode(', ', $details['fields'] ?? []),
            'moved' => 'moveu de '.$quoted('from').' para '.$quoted('to'),
            'subtask_added' => 'adicionou a subtarefa '.$quoted('title'),
            'subtask_completed' => 'concluiu a subtarefa '.$quoted('title'),
            'subtask_reopened' => 'reabriu a subtarefa '.$quoted('title'),
            'subtask_removed' => 'removeu a subtarefa '.$quoted('title'),
            'attachment_added' => 'anexou '.$quoted('name'),
            'attachment_removed' => 'removeu o anexo '.$quoted('name'),
            default => $this->action,
        };
    }
}
