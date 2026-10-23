<?php

namespace App\Http\Requests;

use App\Enums\Sex;
use App\Models\Patient;
use App\Rules\Cpf;
use App\Support\BlindIndex;
use App\Support\BrazilianStates;
use App\Support\Cpf as CpfSupport;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Usado tanto no cadastro quanto na edição de pacientes.
 */
class PatientRequest extends FormRequest
{
    public const CPF_TAKEN_MESSAGE = 'Já existe um paciente cadastrado com este CPF.';

    public function authorize(): bool
    {
        $patient = $this->route('patient');

        return $patient instanceof Patient
            ? $this->user()->can('update', $patient)
            : $this->user()->can('create', Patient::class);
    }

    protected function prepareForValidation(): void
    {
        $digitsOnly = fn (string $field) => $this->filled($field) && is_string($this->input($field))
            ? CpfSupport::digits($this->input($field))
            : $this->input($field);

        $this->merge([
            'cpf' => $digitsOnly('cpf'),
            'phone' => $digitsOnly('phone'),
            'zip_code' => $digitsOnly('zip_code'),
            'state' => is_string($this->input('state')) ? strtoupper($this->input('state')) : $this->input('state'),
        ]);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:3', 'max:150'],
            'cpf' => ['required', 'string', new Cpf, $this->uniqueCpf()],
            'birth_date' => ['required', 'date_format:Y-m-d', 'after:1900-01-01', 'before_or_equal:today'],
            'sex' => ['nullable', Rule::enum(Sex::class)],
            'phone' => ['nullable', 'string', 'regex:/^\d{10,11}$/'],
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'zip_code' => ['nullable', 'string', 'regex:/^\d{8}$/'],
            'street' => ['nullable', 'string', 'max:150'],
            'number' => ['nullable', 'string', 'max:20'],
            'complement' => ['nullable', 'string', 'max:100'],
            'district' => ['nullable', 'string', 'max:100'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', Rule::in(BrazilianStates::CODES)],
            'notes' => ['nullable', 'string', 'max:5000'],
            // Ausente: a autorização fica como está.
            'image_use_consent' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Dados do cadastro, sem a autorização de uso de imagem, que o controller
     * registra com a data à parte.
     *
     * @return array<string, mixed>
     */
    public function patientData(): array
    {
        return $this->safe()->except(['image_use_consent']);
    }

    /**
     * Nulo quando o formulário não tratou da autorização.
     */
    public function imageUseConsent(): ?bool
    {
        return $this->has('image_use_consent') ? $this->boolean('image_use_consent') : null;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'nome',
            'cpf' => 'CPF',
            'birth_date' => 'data de nascimento',
            'sex' => 'sexo',
            'phone' => 'telefone',
            'email' => 'e-mail',
            'zip_code' => 'CEP',
            'street' => 'logradouro',
            'number' => 'número',
            'complement' => 'complemento',
            'district' => 'bairro',
            'city' => 'cidade',
            'state' => 'UF',
            'notes' => 'observações',
            'image_use_consent' => 'autorização de uso de imagem',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'birth_date.date_format' => 'Informe uma data de nascimento válida.',
            'birth_date.after' => 'Informe uma data de nascimento válida.',
            'birth_date.before_or_equal' => 'A data de nascimento não pode estar no futuro.',
            'phone.regex' => 'Informe o telefone com DDD (10 ou 11 dígitos).',
            'zip_code.regex' => 'O CEP deve ter 8 dígitos.',
        ];
    }

    /**
     * O CPF é criptografado, então a unicidade é checada pelo índice cego.
     * Pacientes arquivados também contam.
     */
    private function uniqueCpf(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || ! CpfSupport::isValid($value)) {
                return;
            }

            $taken = Patient::withTrashed()
                ->where('cpf_hash', BlindIndex::for($value))
                ->when($this->route('patient'), fn ($query, Patient $patient) => $query->whereKeyNot($patient->getKey()))
                ->exists();

            if ($taken) {
                $fail(self::CPF_TAKEN_MESSAGE);
            }
        };
    }
}
