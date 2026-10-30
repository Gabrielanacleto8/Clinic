<?php

namespace App\Http\Requests;

use App\Enums\TaskPriority;
use App\Models\Task;
use App\Models\TaskLabel;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filtros do quadro de tarefas e a tarefa aberta no painel.
 */
class TaskBoardFilterRequest extends FormRequest
{
    public const TABS = ['subtarefas', 'comentarios', 'anexos', 'historico'];

    public function authorize(): bool
    {
        return $this->user()->can('viewAny', Task::class);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'responsavel' => ['nullable', 'integer', Rule::exists(User::class, 'id')],
            'prioridade' => ['nullable', Rule::enum(TaskPriority::class)],
            'etiqueta' => ['nullable', 'integer', Rule::exists(TaskLabel::class, 'id')],
            'minhas' => ['nullable', 'boolean'],
            'tarefa' => ['nullable', 'integer'],
            'aba' => ['nullable', Rule::in(self::TABS)],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        return [
            'q' => trim((string) $this->validated('q')),
            'responsavel' => $this->validated('responsavel') !== null ? (int) $this->validated('responsavel') : null,
            'prioridade' => $this->validated('prioridade'),
            'etiqueta' => $this->validated('etiqueta') !== null ? (int) $this->validated('etiqueta') : null,
            'minhas' => $this->boolean('minhas'),
        ];
    }

    public function openTab(): string
    {
        return $this->validated('aba') ?? self::TABS[0];
    }

    public function openTaskId(): ?int
    {
        return $this->validated('tarefa') !== null ? (int) $this->validated('tarefa') : null;
    }
}
