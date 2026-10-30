<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Etiqueta (categoria) de tarefa, criada pela própria clínica.
 */
class TaskLabel extends Model
{
    /** Cores disponíveis: as famílias que o tema escuro já conhece. */
    public const COLORS = [
        'slate' => ['Cinza', 'bg-slate-100 text-slate-700'],
        'sky' => ['Azul', 'bg-sky-100 text-sky-800'],
        'emerald' => ['Verde', 'bg-emerald-100 text-emerald-800'],
        'amber' => ['Âmbar', 'bg-amber-100 text-amber-800'],
        'red' => ['Vermelho', 'bg-red-100 text-red-800'],
        'violet' => ['Violeta', 'bg-violet-100 text-violet-800'],
    ];

    protected $fillable = ['name', 'color'];

    public function tasks(): BelongsToMany
    {
        return $this->belongsToMany(Task::class, 'task_label');
    }

    public function badgeClasses(): string
    {
        return self::COLORS[$this->color][1] ?? self::COLORS['slate'][1];
    }

    /**
     * @return array<string, string>
     */
    public static function colorOptions(): array
    {
        return array_map(fn (array $color) => $color[0], self::COLORS);
    }
}
