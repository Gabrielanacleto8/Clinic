<?php

namespace App\Models;

use App\Enums\TaskColumnKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Coluna do quadro de tarefas. O tipo diz o que estar nela significa.
 */
class TaskColumn extends Model
{
    protected $fillable = ['name', 'kind', 'position'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['kind' => TaskColumnKind::class];
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class)->orderBy('position');
    }
}
