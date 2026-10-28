<?php

namespace App\Http\Requests;

use App\Rules\Cnpj;
use App\Support\Cpf;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Dados da clínica impressos nos documentos.
 */
class ClinicProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage-clinic');
    }

    protected function prepareForValidation(): void
    {
        foreach (['cnpj', 'phone'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => Cpf::digits($this->input($field))]);
            }
        }
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'legal_name' => ['nullable', 'string', 'max:150'],
            'cnpj' => ['nullable', 'string', new Cnpj],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'regex:/^\d{10,11}$/'],
            'email' => ['nullable', 'string', 'email', 'max:150'],
            'technical_lead' => ['nullable', 'string', 'max:150'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'legal_name' => 'razão social',
            'cnpj' => 'CNPJ',
            'address' => 'endereço',
            'phone' => 'telefone',
            'email' => 'e-mail',
            'technical_lead' => 'responsável técnico',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['phone.regex' => 'Informe o telefone com DDD, só com números ou no formato (11) 91234-5678.'];
    }
}
