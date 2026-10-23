<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Evolução ou adendo acrescentado a um atendimento registrado.
 */
class TreatmentNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('addNote', $this->route('treatment'));
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return ['body' => ['required', 'string', 'min:3', 'max:5000']];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['body' => 'evolução'];
    }
}
