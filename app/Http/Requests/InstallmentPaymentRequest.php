<?php

namespace App\Http\Requests;

use App\Enums\PaymentMethod;
use App\Enums\Permission;
use App\Models\FinancialInstallment;
use App\Support\ClinicTime;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Pagamento de uma parcela em aberto. A forma de pagamento pode ser
 * confirmada na hora: o que foi lançado como Pix pode ter sido pago em dinheiro.
 */
class InstallmentPaymentRequest extends FormRequest
{
    /**
     * Quem recebe de paciente ou quem gerencia o financeiro, conforme o
     * lançamento da parcela. A checagem vem antes da validação: um envio que
     * volta com erro reabre o diálogo com o resumo da parcela, e a recepção não
     * pode ler assim uma despesa ou a receita de outro paciente.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        if (! $user->hasPermission(Permission::FinanceReceive) && ! $user->hasPermission(Permission::FinanceManage)) {
            return false;
        }

        $installment = is_numeric($this->input('installment_id'))
            ? FinancialInstallment::query()->with('entry')->find((int) $this->input('installment_id'))
            : null;

        // Parcela inexistente cai na validação ("não está mais em aberto").
        return $installment === null || $user->can('receive', $installment->entry);
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
            'payment_method' => ['nullable', Rule::enum(PaymentMethod::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['installment_id' => 'parcela', 'paid_at' => 'data do pagamento', 'payment_method' => 'forma de pagamento'];
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
