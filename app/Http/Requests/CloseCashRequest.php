<?php

namespace App\Http\Requests;

use App\Enums\PaymentMethod;
use App\Models\FinancialEntry;
use App\Support\ClinicTime;
use App\Support\LocalizedDecimal;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Fechamento do caixa de um dia: o valor conferido em cada forma de pagamento.
 */
class CloseCashRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('closeCash', FinancialEntry::class);
    }

    protected function prepareForValidation(): void
    {
        if (is_array($this->input('counted'))) {
            $this->merge(['counted' => array_map(fn ($value) => LocalizedDecimal::normalize($value), $this->input('counted'))]);
        }
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            // Fecha-se o dia que já aconteceu: hoje ou um dia anterior esquecido.
            'day' => ['required', 'date_format:Y-m-d', 'after:2000-01-01', 'before_or_equal:'.ClinicTime::today()],
            'counted' => ['required', 'array:'.implode(',', array_map(fn (PaymentMethod $method) => $method->value, PaymentMethod::cases()))],
            // Pode ser negativo: no dinheiro, uma despesa paga do caixa maior que o recebido.
            'counted.*' => ['required', 'numeric', 'decimal:0,2', 'min:-9999999.99', 'max:9999999.99'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function counted(): array
    {
        return array_map(fn ($value) => (string) $value, $this->validated('counted'));
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['day' => 'dia', 'counted' => 'valores conferidos', 'counted.*' => 'valor conferido', 'notes' => 'observações'];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'day.before_or_equal' => 'Só se fecha o caixa de hoje ou de um dia anterior.',
            'counted.*.required' => 'Informe o valor conferido.',
            'counted.*.numeric' => 'Informe o valor em reais, como 350,00.',
        ];
    }
}
