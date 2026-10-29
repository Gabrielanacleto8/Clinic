<?php

namespace App\Http\Requests;

use App\Models\FinancialEntry;
use App\Models\Procedure;
use App\Models\User;
use App\Support\LocalizedDecimal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Regras de comissão: o % padrão de cada profissional e as exceções por procedimento.
 */
class CommissionRatesRequest extends FormRequest
{
    public const MAX_EXCEPTIONS = 200;

    public function authorize(): bool
    {
        return $this->user()->can('manage', FinancialEntry::class);
    }

    protected function prepareForValidation(): void
    {
        $normalize = fn ($value) => LocalizedDecimal::normalize($value);

        if (is_array($this->input('defaults'))) {
            $this->merge(['defaults' => array_map($normalize, $this->input('defaults'))]);
        }

        if (is_array($this->input('exceptions'))) {
            $this->merge(['exceptions' => array_map(
                fn ($row) => is_array($row) ? [...$row, 'rate' => $normalize($row['rate'] ?? null)] : $row,
                $this->input('exceptions'),
            )]);
        }
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'defaults' => ['nullable', 'array'],
            'defaults.*' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:100'],
            'exceptions' => ['nullable', 'array', 'max:'.self::MAX_EXCEPTIONS],
            'exceptions.*.user_id' => ['nullable', 'integer', Rule::exists(User::class, 'id')],
            'exceptions.*.procedure_id' => ['nullable', 'required_with:exceptions.*.user_id', 'integer', Rule::exists(Procedure::class, 'id')],
            'exceptions.*.rate' => ['nullable', 'required_with:exceptions.*.user_id', 'numeric', 'decimal:0,2', 'min:0', 'max:100'],
        ];
    }

    /**
     * @return list<callable>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $ids = array_keys($this->input('defaults') ?? []);
            $numeric = array_filter($ids, fn ($id) => is_numeric($id));

            if (count($numeric) !== count($ids) || User::query()->whereKey($numeric)->count() !== count($numeric)) {
                $validator->errors()->add('defaults', 'Profissional inválido. Recarregue a página.');
            }

            $pairs = collect($this->input('exceptions') ?? [])
                ->filter(fn ($row) => is_array($row) && filled($row['user_id'] ?? null))
                ->map(fn (array $row) => $row['user_id'].'-'.($row['procedure_id'] ?? ''));

            if ($pairs->count() !== $pairs->unique()->count()) {
                $validator->errors()->add('exceptions', 'Cada profissional tem uma exceção só por procedimento.');
            }
        }];
    }

    /**
     * @return list<array{user_id: int, procedure_id: int|null, rate: float}>
     */
    public function commissionRules(): array
    {
        $rules = [];

        foreach ($this->validated('defaults') ?? [] as $userId => $rate) {
            if ($rate !== null && $rate !== '') {
                $rules[] = ['user_id' => (int) $userId, 'procedure_id' => null, 'rate' => (float) $rate];
            }
        }

        foreach ($this->validated('exceptions') ?? [] as $row) {
            if (filled($row['user_id'] ?? null)) {
                $rules[] = ['user_id' => (int) $row['user_id'], 'procedure_id' => (int) $row['procedure_id'], 'rate' => (float) $row['rate']];
            }
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['defaults.*' => 'comissão padrão', 'exceptions.*.procedure_id' => 'procedimento', 'exceptions.*.rate' => 'comissão'];
    }
}
