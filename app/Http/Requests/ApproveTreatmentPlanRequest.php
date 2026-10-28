<?php

namespace App\Http\Requests;

use App\Enums\PaymentMethod;
use App\Enums\Permission;
use App\Models\TreatmentPlan;
use App\Support\InstallmentPlan;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Aprovação do orçamento: como o pacote será pago. A cobrança nasce com estes dados.
 */
class ApproveTreatmentPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('approve', $this->route('treatmentPlan'));
    }

    protected function prepareForValidation(): void
    {
        // Checkbox desmarcado não é enviado pelo navegador.
        $this->merge(['first_installment_paid' => $this->boolean('first_installment_paid')]);
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
            'first_installment_paid' => ['required', 'boolean'],
        ];
    }

    /**
     * @return list<callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->has('installments')) {
                    return;
                }

                /** @var TreatmentPlan $plan */
                $plan = $this->route('treatmentPlan');
                $total = $plan->loadMissing('items')->total();

                if ((float) $total > 0 && ! InstallmentPlan::fits($total, (int) $this->input('installments'))) {
                    $validator->errors()->add('installments', InstallmentPlan::TOO_MANY_MESSAGE);
                }
            },
        ];
    }

    /**
     * @return array{payment_method: PaymentMethod, installments: int, first_due_date: string, first_installment_paid: bool}
     */
    public function payment(): array
    {
        return [
            'payment_method' => PaymentMethod::from($this->validated('payment_method')),
            'installments' => (int) $this->validated('installments'),
            'first_due_date' => $this->validated('first_due_date'),
            // Dar por recebido é registrar um pagamento: só com a permissão de receber.
            'first_installment_paid' => $this->boolean('first_installment_paid') && $this->user()->hasPermission(Permission::FinanceReceive),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'payment_method' => 'forma de pagamento',
            'installments' => 'número de parcelas',
            'first_due_date' => 'primeiro vencimento',
        ];
    }
}
