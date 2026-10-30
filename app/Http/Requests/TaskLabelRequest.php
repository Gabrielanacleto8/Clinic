<?php

namespace App\Http\Requests;

use App\Models\Task;
use App\Models\TaskLabel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TaskLabelRequest extends FormRequest
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
            'name' => ['required', 'string', 'min:2', 'max:40', Rule::unique(TaskLabel::class, 'name')->ignore($this->route('label'))],
            'color' => ['required', Rule::in(array_keys(TaskLabel::COLORS))],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['name' => 'nome da etiqueta', 'color' => 'cor'];
    }
}
