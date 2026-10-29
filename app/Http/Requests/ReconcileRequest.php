<?php

namespace App\Http\Requests;

use App\Enums\FinancialEntryType;
use App\Enums\PaymentMethod;
use App\Models\FinancialEntry;
use App\Models\FinancialInstallment;
use App\Support\ClinicTime;
use App\Support\LocalizedDecimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Recebimentos conferidos no extrato: quais, em que data caíram e a taxa de cada um.
 */
class ReconcileRequest extends FormRequest
{
    public const MAX_ROWS = 100;

    public function authorize(): bool
    {
        return $this->user()->can('reconcile', FinancialEntry::class);
    }

    protected function prepareForValidation(): void
    {
        if (is_array($this->input('fees'))) {
            $this->merge(['fees' => array_map(fn ($value) => LocalizedDecimal::normalize($value), $this->input('fees'))]);
        }
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'installments' => ['required', 'array', 'max:'.self::MAX_ROWS],
            'installments.*' => ['integer', 'distinct'],
            'reconciled_on' => ['required', 'date_format:Y-m-d', 'after:2000-01-01', 'before_or_equal:'.ClinicTime::today()],
            'fees' => ['nullable', 'array'],
            'fees.*' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999.99'],
        ];
    }

    /**
     * @return list<callable>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->has('installments*')) {
                return;
            }

            $ids = array_map('intval', $this->input('installments'));
            $found = $this->eligible()->whereKey($ids)->get(['id', 'amount', 'paid_at'])->keyBy('id');

            if ($found->count() !== count($ids)) {
                $validator->errors()->add('installments', 'Algum recebimento escolhido não está mais pendente de conciliação. Recarregue a página.');

                return;
            }

            foreach ($found as $id => $installment) {
                $fee = $this->input("fees.{$id}");

                if (is_numeric($fee) && (float) $fee >= (float) $installment->amount) {
                    $validator->errors()->add("fees.{$id}", 'A taxa tem de ser menor que o valor recebido.');
                }

                if ($this->input('reconciled_on') < $installment->paid_at->format('Y-m-d')) {
                    $validator->errors()->add('reconciled_on', 'A data no extrato não pode ser anterior ao recebimento ('.$installment->paid_at->format('d/m/Y').').');
                }
            }
        }];
    }

    /**
     * Recebimentos que podem ser conciliados: receita paga, fora do dinheiro, ainda não conferida.
     *
     * @return Builder<FinancialInstallment>
     */
    public static function eligible(): Builder
    {
        return FinancialInstallment::query()
            ->whereNotNull('paid_at')
            ->whereNull('reconciled_on')
            ->whereHas('entry', fn ($entry) => $entry->where('type', FinancialEntryType::Income->value)->where('payment_method', '!=', PaymentMethod::Cash->value));
    }

    /**
     * @return array<int, string|null> Parcela => taxa.
     */
    public function fees(): array
    {
        $fees = [];

        foreach ($this->validated('installments') as $id) {
            $fee = $this->validated('fees')[$id] ?? null;
            $fees[(int) $id] = is_numeric($fee) && (float) $fee > 0 ? number_format((float) $fee, 2, '.', '') : null;
        }

        return $fees;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['installments' => 'recebimentos', 'reconciled_on' => 'data no extrato', 'fees.*' => 'taxa'];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['installments.required' => 'Marque ao menos um recebimento.', 'fees.*.numeric' => 'Informe a taxa em reais, como 4,99.'];
    }
}
