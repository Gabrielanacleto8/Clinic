<?php

namespace App\Http\Requests;

use App\Enums\AuditAction;
use App\Enums\Sex;
use App\Models\Patient;
use App\Rules\Cpf;
use App\Services\AuditLogger;
use App\Support\BlindIndex;
use App\Support\BrazilianStates;
use App\Support\Cpf as CpfSupport;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Usado tanto no cadastro quanto na edição de pacientes.
 */
class PatientRequest extends FormRequest
{
    public const CPF_TAKEN_MESSAGE = 'Já existe um paciente cadastrado com este CPF.';

    public const CPF_ARCHIVED_MESSAGE = 'Este CPF pertence a um cadastro arquivado.';

    /**
     * Para quem não vê arquivados: não diz que o cadastro está arquivado, mas
     * não deixa a recepção sem saída quando ele não aparece na busca.
     */
    public const CPF_HIDDEN_MESSAGE = 'Já existe um paciente cadastrado com este CPF. Se ele não aparece na busca de pacientes, peça ao administrador.';

    /** Idade a partir da qual o paciente responde por si no cadastro. */
    public const ADULT_AGE = 18;

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
            'guardian_cpf' => $digitsOnly('guardian_cpf'),
            'guardian_phone' => $digitsOnly('guardian_phone'),
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
            // Obrigatórios para menor de idade; ver after().
            'guardian_name' => ['nullable', 'string', 'min:3', 'max:150'],
            'guardian_cpf' => ['nullable', 'string', new Cpf],
            'guardian_relationship' => ['nullable', 'string', 'max:50'],
            'guardian_phone' => ['nullable', 'string', 'regex:/^\\d{10,11}$/'],
        ];
    }

    /**
     * @return list<callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->has('birth_date') || ! $this->isMinor()) {
                    return;
                }

                foreach (['guardian_name' => 'Informe o nome do responsável legal: o paciente é menor de idade.',
                    'guardian_relationship' => 'Informe o parentesco do responsável legal.',
                    'guardian_phone' => 'Informe o telefone do responsável legal.'] as $field => $message) {
                    if (! $this->filled($field) && ! $validator->errors()->has($field)) {
                        $validator->errors()->add($field, $message);
                    }
                }
            },
        ];
    }

    /**
     * Menor de idade pela data de nascimento enviada.
     */
    public function isMinor(): bool
    {
        $birthDate = $this->input('birth_date');

        if (! is_string($birthDate) || ! preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $birthDate)) {
            return false;
        }

        try {
            return CarbonImmutable::createFromFormat('Y-m-d', $birthDate)->age < self::ADULT_AGE;
        } catch (InvalidFormatException) {
            return false;
        }
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
            'guardian_name' => 'nome do responsável',
            'guardian_cpf' => 'CPF do responsável',
            'guardian_relationship' => 'parentesco do responsável',
            'guardian_phone' => 'telefone do responsável',
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
            'guardian_phone.regex' => 'Informe o telefone do responsável com DDD (10 ou 11 dígitos).',
        ];
    }

    /**
     * O CPF é criptografado, então a unicidade é checada pelo índice cego.
     * Pacientes arquivados também contam. Quando o CPF já existe, a tela
     * recebe o caminho para o cadastro (abrir ou restaurar), em vez de um
     * beco sem saída; o paciente encontrado fica na auditoria só pelo id.
     */
    private function uniqueCpf(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || ! CpfSupport::isValid($value)) {
                return;
            }

            $owner = Patient::withTrashed()
                ->where('cpf_hash', BlindIndex::for($value))
                ->when($this->route('patient'), fn ($query, Patient $patient) => $query->whereKeyNot($patient->getKey()))
                ->first(['id', 'deleted_at']);

            if ($owner === null) {
                return;
            }

            app(AuditLogger::class)->log(AuditAction::PatientLookup, $owner, ['archived' => $owner->trashed()]);

            // Que o cadastro está arquivado só é dito a quem pode ver arquivados.
            $revealArchived = $owner->trashed() && $this->user()->can('viewArchived', Patient::class);

            if (! $owner->trashed() || $revealArchived) {
                // Vai junto com o redirecionamento de volta ao formulário.
                $this->session()->flash('cpf_owner', ['id' => $owner->getKey(), 'archived' => $owner->trashed()]);
            }

            $fail(match (true) {
                $revealArchived => self::CPF_ARCHIVED_MESSAGE,
                $owner->trashed() => self::CPF_HIDDEN_MESSAGE,
                default => self::CPF_TAKEN_MESSAGE,
            });
        };
    }
}
