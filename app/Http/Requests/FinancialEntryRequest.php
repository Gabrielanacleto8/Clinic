<?php

namespace App\Http\Requests;

use App\Enums\FinancialCategory;
use App\Enums\FinancialEntryType;
use App\Enums\PaymentMethod;
use App\Models\FinancialEntry;
use App\Models\Patient;
use App\Support\ClinicTime;
use App\Support\InstallmentPlan;
use App\Support\LocalizedDecimal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class FinancialEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', FinancialEntry::class);
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'total' => LocalizedDecimal::normalize($this->input('total')),
            // Checkbox desmarcado não é enviado pelo navegador.
            'first_installment_paid' => $this->boolean('first_installment_paid'),
        ]);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $type = FinancialEntryType::tryFrom((string) $this->input('type'));

        return [
            'type' => ['required', Rule::enum(FinancialEntryType::class)],
            // A categoria tem de ser do tipo escolhido.
            'category' => ['required', Rule::in($type ? array_keys(FinancialCategory::optionsFor($type)) : [])],
            'description' => ['required', 'string', 'min:2', 'max:200'],
            'issued_on' => ['required', 'date_format:Y-m-d', 'after:2000-01-01', 'before_or_equal:'.ClinicTime::today()],
            'total' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:9999999.99'],
            'payment_method' => ['required', Rule::enum(PaymentMethod::class)],
            'installments' => ['required', 'integer', 'min:1', 'max:'.InstallmentPlan::MAX_INSTALLMENTS],
            'first_due_date' => ['required', 'date_format:Y-m-d', 'after:2000-01-01', 'before:2100-01-01'],
            'first_installment_paid' => ['required', 'boolean'],
            // Receita de paciente fora do atendimento (venda de produto, pacote): aparece na ficha dele.
            'patient_id' => [$type === FinancialEntryType::Income ? 'nullable' : 'prohibited', 'integer', Rule::exists(Patient::class, 'id')->withoutTrashed()],
        ];
    }

    /**
     * @return list<callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $validator->errors()->hasAny(['total', 'installments'])
                    && ! InstallmentPlan::fits((string) $this->input('total'), (int) $this->input('installments'))) {
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
        return [
            'type' => 'tipo',
            'category' => 'categoria',
            'description' => 'descrição',
            'issued_on' => 'data',
            'total' => 'valor total',
            'payment_method' => 'forma de pagamento',
            'installments' => 'número de parcelas',
            'first_due_date' => 'primeiro vencimento',
            'first_installment_paid' => 'primeira parcela paga',
            'patient_id' => 'paciente',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'category.in' => 'Escolha uma categoria compatível com o tipo do lançamento.',
            'total.gt' => 'O valor deve ser maior que zero.',
            'issued_on.before_or_equal' => 'A data do lançamento não pode estar no futuro.',
            'patient_id.exists' => 'Busque e selecione um paciente na lista.',
        ];
    }

    /**
     * Paciente devolvido pelo formulário que voltou com erro, para o seletor
     * reabrir com ele escolhido (na página ou na janela de nova receita).
     */
    public static function oldPatient(Request $request): ?Patient
    {
        $id = $request->old('patient_id');

        return is_numeric($id) ? Patient::query()->find((int) $id, ['id', 'name']) : null;
    }
}
