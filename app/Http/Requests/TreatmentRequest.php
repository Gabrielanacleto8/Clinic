<?php

namespace App\Http\Requests;

use App\Enums\PaymentMethod;
use App\Enums\Permission;
use App\Enums\UserRole;
use App\Models\Appointment;
use App\Models\Procedure;
use App\Models\ProductBatch;
use App\Models\Treatment;
use App\Models\User;
use App\Services\RolePermissions;
use App\Services\TreatmentRecorder;
use App\Support\ClinicTime;
use App\Support\InstallmentPlan;
use App\Support\LocalizedDecimal;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Registro de um atendimento: o que foi feito, os produtos usados e a cobrança.
 */
class TreatmentRequest extends FormRequest
{
    public const MAX_PRODUCTS = 10;

    public function authorize(): bool
    {
        return $this->user()->can('create', Treatment::class);
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'amount' => LocalizedDecimal::normalize($this->input('amount')),
            // Checkbox desmarcado não é enviado pelo navegador.
            'first_installment_paid' => $this->boolean('first_installment_paid'),
        ]);

        if (is_array($this->input('products'))) {
            // Quantidades digitadas no padrão brasileiro.
            $this->merge([
                'products' => array_map(
                    fn ($row) => is_array($row) ? [...$row, 'quantity' => LocalizedDecimal::normalize($row['quantity'] ?? null)] : $row,
                    $this->input('products'),
                ),
            ]);
        }
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
            'performed_at' => ['required', 'date_format:'.ClinicTime::INPUT_FORMAT],
            'procedure_id' => ['required', 'integer', Rule::exists(Procedure::class, 'id')->where('is_active', true)],
            'treated_areas' => ['nullable', 'string', 'max:255'],
            'assessment' => ['nullable', 'string', 'max:5000'],
            'description' => ['required', 'string', 'max:10000'],
            'post_care' => ['nullable', 'string', 'max:5000'],
            'complications' => ['nullable', 'string', 'max:5000'],
            'return_date' => ['nullable', 'date_format:Y-m-d'],

            // Só tem efeito para administradores; ver TreatmentController.
            'professional_id' => [
                'nullable',
                'integer',
                Rule::exists(User::class, 'id')
                    ->where('is_active', true)
                    ->whereIn('role', array_map(
                        fn (UserRole $role) => $role->value,
                        app(RolePermissions::class)->rolesWith(Permission::RecordsManage),
                    )),
            ],

            'appointment_id' => $this->appointmentRules(),

            'products' => ['nullable', 'array', 'max:'.self::MAX_PRODUCTS],
            'products.*.product_batch_id' => [
                'nullable',
                'integer',
                'distinct',
                // Lote vencido ou de produto inativo não pode ser escolhido.
                Rule::exists(ProductBatch::class, 'id')->where(
                    fn ($query) => $query
                        ->where(fn ($inner) => $inner->whereNull('expires_on')->orWhere('expires_on', '>=', ClinicTime::today()))
                        ->whereIn('product_id', fn ($products) => $products->select('id')->from('products')->where('is_active', true)),
                ),
            ],
            'products.*.quantity' => ['nullable', 'required_with:products.*.product_batch_id', 'numeric', 'decimal:0,3', 'gt:0', 'max:999999'],

            'amount' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999.99'],
            'payment_method' => [$whenCharged, Rule::enum(PaymentMethod::class)],
            'installments' => [$whenCharged, 'integer', 'min:1', 'max:'.InstallmentPlan::MAX_INSTALLMENTS],
            'first_due_date' => [$whenCharged, 'date_format:Y-m-d', 'after:2000-01-01', 'before:2100-01-01'],
            'first_installment_paid' => ['required', 'boolean'],
        ];
    }

    /**
     * Agendamento que o atendimento encerra: tem de ser deste paciente e
     * estar aberto para receber o registro.
     *
     * @return list<mixed>
     */
    protected function appointmentRules(): array
    {
        return [
            'bail',
            'nullable',
            'integer',
            function (string $attribute, mixed $value, Closure $fail): void {
                $open = Appointment::query()
                    ->whereKey((int) $value)
                    ->where('patient_id', $this->route('patient')->getKey())
                    ->openForTreatment()
                    ->exists();

                if (! $open) {
                    $fail(TreatmentRecorder::APPOINTMENT_NOT_OPEN);
                }
            },
        ];
    }

    /**
     * @return list<callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->has('performed_at')) {
                    return;
                }

                $performedAt = ClinicTime::fromInput($this->input('performed_at'));

                // Tolerância pequena para relógios levemente adiantados.
                if ($performedAt->isAfter(now()->addMinutes(5))) {
                    $validator->errors()->add('performed_at', 'A data do atendimento não pode estar no futuro.');
                }

                if ($performedAt->year < 2000) {
                    $validator->errors()->add('performed_at', 'Informe uma data de atendimento válida.');
                }

                if ($this->filled('return_date') && ! $validator->errors()->has('return_date')
                    && $this->input('return_date') < ClinicTime::local($performedAt)->format('Y-m-d')) {
                    $validator->errors()->add('return_date', 'O retorno não pode ser anterior ao atendimento.');
                }
            },
        ];
    }

    /**
     * Linhas de produto preenchidas, já sem as linhas em branco do formulário.
     *
     * @return list<array{product_batch_id: int, quantity: string}>
     */
    public function products(): array
    {
        return collect($this->validated('products') ?? [])
            ->filter(fn (array $row) => filled($row['product_batch_id'] ?? null))
            ->map(fn (array $row) => [
                'product_batch_id' => (int) $row['product_batch_id'],
                'quantity' => number_format((float) $row['quantity'], 3, '.', ''),
            ])
            ->values()
            ->all();
    }

    /**
     * Dados de pagamento prontos para o TreatmentRecorder, ou nulo quando não há cobrança.
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
            'performed_at' => 'data do atendimento',
            'procedure_id' => 'procedimento',
            'treated_areas' => 'áreas tratadas',
            'assessment' => 'avaliação',
            'description' => 'descrição',
            'post_care' => 'orientações pós-procedimento',
            'complications' => 'intercorrências',
            'return_date' => 'retorno previsto',
            'professional_id' => 'profissional',
            'products' => 'produtos',
            'products.*.product_batch_id' => 'produto',
            'products.*.quantity' => 'quantidade',
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
        return [
            'performed_at.date_format' => 'Informe uma data e hora de atendimento válidas.',
            'return_date.date_format' => 'Informe uma data de retorno válida.',
            'products.*.product_batch_id.exists' => 'Este lote não existe, está vencido ou é de um produto inativo.',
            'products.*.product_batch_id.distinct' => 'Este lote está repetido na lista.',
            'products.*.quantity.required_with' => 'Informe a quantidade utilizada deste produto.',
            'amount.numeric' => 'Informe o valor em reais, como 850,00. Use 0 para atendimento sem cobrança.',
        ];
    }
}
