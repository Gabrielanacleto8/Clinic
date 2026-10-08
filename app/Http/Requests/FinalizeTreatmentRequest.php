<?php

namespace App\Http\Requests;

use App\Enums\PaymentMethod;
use App\Support\InstallmentPlan;
use App\Support\LocalizedDecimal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FinalizeTreatmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('finalize', $this->route('treatment'));
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'amount' => LocalizedDecimal::normalize($this->input('amount')),
            // Checkbox desmarcado não é enviado pelo navegador.
            'first_installment_paid' => $this->boolean('first_installment_paid'),
        ]);
    }

    private function hasCharge(): bool
    {
        return is_numeric($this->input('amount')) && (float) $this->input('amount') > 0;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        // Sem cobrança (cortesia, retorno), os dados de pagamento não se aplicam.
        $whenCharged = $this->hasCharge() ? 'required' : 'nullable';

        return [
            'amount' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999.99'],
            'payment_method' => [$whenCharged, Rule::enum(PaymentMethod::class)],
            'installments' => [$whenCharged, 'integer', 'min:1', 'max:'.InstallmentPlan::MAX_INSTALLMENTS],
            'first_due_date' => [$whenCharged, 'date_format:Y-m-d', 'after:2000-01-01', 'before:2100-01-01'],
            'first_installment_paid' => ['required', 'boolean'],
        ];
    }

    /**
     * Dados de pagamento prontos para o TreatmentFinalizer, ou nulo quando não há cobrança.
     *
     * @return array{amount: string, payment_method: PaymentMethod, installments: int, first_due_date: string, first_installment_paid: bool}|null
     */
    public function payment(): ?array
    {
        if (! $this->hasCharge()) {
            return null;
        }

        return [
            'amount' => (string) $this->validated('amount'),
            'payment_method' => PaymentMethod::from($this->validated('payment_method')),
            'installments' => (int) $this->validated('installments'),
            'first_due_date' => $this->validated('first_due_date'),
            'first_installment_paid' => $this->boolean('first_installment_paid'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'amount' => 'valor do atendimento',
            'payment_method' => 'forma de pagamento',
            'installments' => 'número de parcelas',
            'first_due_date' => 'primeiro vencimento',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['amount.numeric' => 'Informe o valor em reais, como 850,00. Use 0 para atendimento sem cobrança.'];
    }
}
