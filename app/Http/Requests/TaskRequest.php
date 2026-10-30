<?php

namespace App\Http\Requests;

use App\Enums\TaskPriority;
use App\Models\Task;
use App\Models\TaskColumn;
use App\Models\TaskLabel;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Criação e edição de tarefa, pela janela de nova tarefa ou pelo painel.
 */
class TaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        $task = $this->route('task');

        return $task instanceof Task ? $this->user()->can('update', $task) : $this->user()->can('create', Task::class);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'min:2', 'max:150'],
            'description' => ['nullable', 'string', 'max:5000'],
            'task_column_id' => ['required', 'integer', Rule::exists(TaskColumn::class, 'id')],
            'priority' => ['required', Rule::enum(TaskPriority::class)],
            'assignee_id' => ['nullable', 'integer', Rule::exists(User::class, 'id')->where('is_active', true)],
            'starts_on' => ['nullable', 'date_format:Y-m-d'],
            'due_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'labels' => ['nullable', 'array', 'max:10'],
            'labels.*' => ['integer', Rule::exists(TaskLabel::class, 'id')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'title' => 'título', 'description' => 'descrição', 'task_column_id' => 'coluna', 'priority' => 'prioridade',
            'assignee_id' => 'responsável', 'starts_on' => 'data de início', 'due_on' => 'prazo', 'labels' => 'etiquetas',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['due_on.after_or_equal' => 'O prazo não pode ser antes da data de início.'];
    }

    /**
     * @return list<int>
     */
    public function labelIds(): array
    {
        return array_map('intval', $this->validated('labels') ?? []);
    }
}
