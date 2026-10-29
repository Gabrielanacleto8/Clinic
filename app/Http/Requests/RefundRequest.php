<?php

namespace App\Http\Requests;

use App\Enums\PaymentMethod;
use App\Enums\RefundReason;
use App\Models\FinancialEntry;
use App\Support\ClinicTime;
use App\Support\LocalizedDecimal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Estorno de uma cobrança de paciente: quanto volta, quando, como e por quê.
 */
class RefundRequest extends FormRequest
{
    /**
     * A checagem vem antes da validação, sobre a cobrança informada:
     * lançamento inexistente cai na validação.
     */
    public function authorize(): bool
    {
        $entry = is_numeric($this->input('entry_id')) ? FinancialEntry::query()->find((int) $this->input('entry_id')) : null;

        return $entry === null
            ? $this->user()->can('manage', FinancialEntry::class)
            : $this->user()->can('refund', $entry);
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['amount' => LocalizedDecimal::normalize($this->input('amount'))]);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'entry_id' => ['required', 'integer', Rule::exists(FinancialEntry::class, 'id')],
            'amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:9999999.99'],
            'refunded_on' => ['required', 'date_format:Y-m-d', 'after:2000-01-01', 'before_or_equal:'.ClinicTime::today()],
            'payment_method' => ['required', Rule::enum(PaymentMethod::class)],
            'reason' => ['required', Rule::enum(RefundReason::class)],
        ];
    }

    public function entry(): FinancialEntry
    {
        return FinancialEntry::query()->findOrFail((int) $this->validated('entry_id'));
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'entry_id' => 'cobrança',
            'amount' => 'valor devolvido',
            'refunded_on' => 'data da devolução',
            'payment_method' => 'forma da devolução',
            'reason' => 'motivo',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['amount.numeric' => 'Informe o valor em reais, como 150,00.', 'amount.gt' => 'Informe um valor maior que zero.'];
    }
}
