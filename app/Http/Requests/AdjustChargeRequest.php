<?php

namespace App\Http\Requests;

use App\Enums\PaymentMethod;
use App\Support\InstallmentPlan;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Ajuste da cobrança de um atendimento: forma de pagamento, parcelas e
 * primeiro vencimento. O valor não muda, e nada foi recebido ainda.
 */
class AdjustChargeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('adjustCharge', $this->route('entry'));
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'payment_method' => ['required', Rule::enum(PaymentMethod::class)],
            'installments' => ['required', 'integer', 'min:1', 'max:'.InstallmentPlan::MAX_INSTALLMENTS],
            'first_due_date' => ['required', 'date_format:Y-m-d', 'after:2000-01-01', 'before:2100-01-01'],
        ];
    }

    /**
     * @return list<callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $validator->errors()->has('installments')
                    && ! InstallmentPlan::fits($this->route('entry')->total(), (int) $this->input('installments'))) {
                    $validator->errors()->add('installments', InstallmentPlan::TOO_MANY_MESSAGE);
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['payment_method' => 'forma de pagamento', 'installments' => 'número de parcelas', 'first_due_date' => 'primeiro vencimento'];
    }
}
