<?php

namespace App\Http\Requests;

use App\Enums\PaymentMethod;
use App\Models\FinancialEntry;
use App\Support\ClinicTime;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CommissionPayoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage', FinancialEntry::class);
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['paid_now' => $this->boolean('paid_now')]);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'until' => ['required', 'date_format:Y-m-d', 'after:2000-01-01', 'before_or_equal:'.ClinicTime::today()],
            'payment_method' => ['required', Rule::enum(PaymentMethod::class)],
            'paid_now' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['until' => 'atendimentos até', 'payment_method' => 'forma de pagamento'];
    }
}
