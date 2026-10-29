<?php

namespace App\Http\Requests;

use App\Enums\FinancialCategory;
use App\Enums\FinancialEntryType;
use App\Enums\PaymentMethod;
use App\Models\FinancialEntry;
use App\Support\LocalizedDecimal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Cadastro e alteração de uma despesa recorrente. A alteração vale para os
 * meses ainda não gerados; as despesas já lançadas ficam como estão.
 */
class RecurringExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage', FinancialEntry::class);
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'amount' => LocalizedDecimal::normalize($this->input('amount')),
            'is_active' => $this->boolean('is_active', true),
        ]);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'category' => ['required', Rule::in(array_keys(FinancialCategory::optionsFor(FinancialEntryType::Expense)))],
            'description' => ['required', 'string', 'max:200'],
            'amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:9999999.99'],
            'payment_method' => ['required', Rule::enum(PaymentMethod::class)],
            'due_day' => ['required', 'integer', 'min:1', 'max:28'],
            'starts_on' => ['required', 'date_format:Y-m-d', 'after:2000-01-01', 'before:2100-01-01'],
            'ends_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:starts_on', 'before:2100-01-01'],
            'is_active' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function expenseData(): array
    {
        return [...$this->safe()->only(['category', 'description', 'payment_method', 'due_day', 'starts_on', 'ends_on', 'is_active']), 'amount' => (string) $this->validated('amount')];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'category' => 'categoria',
            'description' => 'descrição',
            'amount' => 'valor',
            'payment_method' => 'forma de pagamento',
            'due_day' => 'dia do vencimento',
            'starts_on' => 'início',
            'ends_on' => 'fim',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'amount.numeric' => 'Informe o valor em reais, como 3.500,00.',
            'due_day.max' => 'Escolha um dia até 28, que existe em todos os meses.',
            'ends_on.after_or_equal' => 'O fim não pode ser antes do início.',
        ];
    }
}
