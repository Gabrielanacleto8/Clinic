<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Anulação de um atendimento registrado com erro.
 */
class VoidTreatmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('void', $this->route('treatment'));
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['reason' => 'motivo da anulação'];
    }
}
