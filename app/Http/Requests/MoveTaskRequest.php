<?php

namespace App\Http\Requests;

use App\Models\TaskColumn;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Arrastar a tarefa para outra coluna ou posição.
 */
class MoveTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('task'));
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'task_column_id' => ['required', 'integer', Rule::exists(TaskColumn::class, 'id')],
            'position' => ['required', 'integer', 'min:0', 'max:100000'],
        ];
    }
}
