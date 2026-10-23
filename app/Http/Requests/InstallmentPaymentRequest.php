<?php

namespace App\Http\Requests;

use App\Models\FinancialEntry;
use App\Models\FinancialInstallment;
use App\Support\ClinicTime;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Pagamento de uma parcela em aberto.
 */
class InstallmentPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('settle', FinancialEntry::class);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            // Só parcelas ainda em aberto: pagar duas vezes não é possível.
            'installment_id' => ['required', 'integer', Rule::exists(FinancialInstallment::class, 'id')->whereNull('paid_at')],
            'paid_at' => ['required', 'date_format:Y-m-d', 'after:2000-01-01', 'before_or_equal:'.ClinicTime::today()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['installment_id' => 'parcela', 'paid_at' => 'data do pagamento'];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'installment_id.exists' => 'Esta parcela não está mais em aberto.',
            'paid_at.before_or_equal' => 'A data do pagamento não pode estar no futuro.',
        ];
    }
}
