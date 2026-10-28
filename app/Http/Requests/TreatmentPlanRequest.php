<?php

namespace App\Http\Requests;

use App\Models\Procedure;
use App\Models\TreatmentPlan;
use App\Support\LocalizedDecimal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Orçamento em aberto: validade, observações e os procedimentos com sessões e valor.
 */
class TreatmentPlanRequest extends FormRequest
{
    public const MAX_ITEMS = 15;

    public const MAX_SESSIONS = 50;

    public function authorize(): bool
    {
        $plan = $this->route('treatmentPlan');

        return $plan instanceof TreatmentPlan
            ? $this->user()->can('update', $plan)
            : $this->user()->can('create', TreatmentPlan::class);
    }

    protected function prepareForValidation(): void
    {
        if (is_array($this->input('items'))) {
            $this->merge([
                'items' => array_map(
                    fn ($row) => is_array($row) ? [...$row, 'unit_price' => LocalizedDecimal::normalize($row['unit_price'] ?? null)] : $row,
                    $this->input('items'),
                ),
            ]);
        }
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'valid_until' => ['nullable', 'date_format:Y-m-d', 'after:2000-01-01', 'before:2100-01-01'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'items' => ['required', 'array', 'max:'.self::MAX_ITEMS],
            'items.*.procedure_id' => ['nullable', 'integer', Rule::exists(Procedure::class, 'id')->where('is_active', true)],
            'items.*.sessions' => ['nullable', 'required_with:items.*.procedure_id', 'integer', 'min:1', 'max:'.self::MAX_SESSIONS],
            'items.*.unit_price' => ['nullable', 'required_with:items.*.procedure_id', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999.99'],
        ];
    }

    /**
     * @return list<callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->has('items')) {
                    return;
                }

                $procedureIds = collect($this->input('items'))->pluck('procedure_id')->filter(filled(...));

                if ($procedureIds->isEmpty()) {
                    $validator->errors()->add('items', 'Inclua ao menos um procedimento.');
                }

                // Um procedimento por linha: as sessões somam na mesma linha.
                if ($procedureIds->count() !== $procedureIds->unique()->count()) {
                    $validator->errors()->add('items', 'Cada procedimento entra uma vez só; some as sessões na mesma linha.');
                }
            },
        ];
    }

    /**
     * @return array{valid_until: ?string, notes: ?string}
     */
    public function planData(): array
    {
        return ['valid_until' => $this->validated('valid_until'), 'notes' => $this->validated('notes')];
    }

    /**
     * Linhas preenchidas, sem as linhas em branco do formulário.
     *
     * @return list<array{procedure_id: int, sessions: int, unit_price: string}>
     */
    public function items(): array
    {
        return collect($this->validated('items'))
            ->filter(fn (array $row) => filled($row['procedure_id'] ?? null))
            ->map(fn (array $row) => [
                'procedure_id' => (int) $row['procedure_id'],
                'sessions' => (int) $row['sessions'],
                'unit_price' => number_format((float) $row['unit_price'], 2, '.', ''),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'valid_until' => 'validade',
            'notes' => 'observações',
            'items' => 'procedimentos',
            'items.*.procedure_id' => 'procedimento',
            'items.*.sessions' => 'sessões',
            'items.*.unit_price' => 'valor por sessão',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'items.*.procedure_id.exists' => 'Este procedimento não existe ou está inativo.',
            'items.*.sessions.required_with' => 'Informe quantas sessões.',
            'items.*.unit_price.required_with' => 'Informe o valor de cada sessão.',
            'items.*.unit_price.numeric' => 'Informe o valor em reais, como 450,00.',
        ];
    }
}
