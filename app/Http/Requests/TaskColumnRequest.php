<?php

namespace App\Http\Requests;

use App\Enums\TaskColumnKind;
use App\Models\Task;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TaskColumnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('configure', Task::class);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:60'],
            'kind' => ['required', Rule::enum(TaskColumnKind::class)],
            'position' => ['required', 'integer', 'min:1', 'max:50'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['name' => 'nome da coluna', 'kind' => 'tipo', 'position' => 'ordem'];
    }
}
