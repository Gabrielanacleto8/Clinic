<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AnamnesisRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('updateClinicalRecords', $this->route('patient'));
    }

    protected function prepareForValidation(): void
    {
        // Checkbox desmarcado não é enviado pelo navegador.
        $this->merge(['pregnant_or_lactating' => $this->boolean('pregnant_or_lactating')]);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'allergies' => ['nullable', 'string', 'max:2000'],
            'medications' => ['nullable', 'string', 'max:2000'],
            'health_conditions' => ['nullable', 'string', 'max:2000'],
            'previous_procedures' => ['nullable', 'string', 'max:2000'],
            'pregnant_or_lactating' => ['required', 'boolean'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'allergies' => 'alergias',
            'medications' => 'medicamentos em uso',
            'health_conditions' => 'condições de saúde',
            'previous_procedures' => 'procedimentos anteriores',
            'pregnant_or_lactating' => 'gestante ou lactante',
            'notes' => 'observações',
        ];
    }
}
