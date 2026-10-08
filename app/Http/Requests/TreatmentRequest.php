<?php

namespace App\Http\Requests;

use App\Enums\AppointmentStatus;
use App\Enums\Permission;
use App\Enums\UserRole;
use App\Models\Appointment;
use App\Models\Procedure;
use App\Models\ProductBatch;
use App\Models\Treatment;
use App\Models\User;
use App\Services\RolePermissions;
use App\Support\ClinicTime;
use App\Support\LocalizedDecimal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Usado tanto no registro quanto na edição de atendimentos em andamento.
 */
class TreatmentRequest extends FormRequest
{
    public const MAX_PRODUCTS = 10;

    public function authorize(): bool
    {
        $treatment = $this->route('treatment');

        return $treatment instanceof Treatment
            ? $this->user()->can('update', $treatment)
            : $this->user()->can('create', Treatment::class);
    }

    protected function prepareForValidation(): void
    {
        if (! is_array($this->input('products'))) {
            return;
        }

        // Quantidades digitadas no padrão brasileiro.
        $this->merge([
            'products' => array_map(
                fn ($row) => is_array($row) ? [...$row, 'quantity' => LocalizedDecimal::normalize($row['quantity'] ?? null)] : $row,
                $this->input('products'),
            ),
        ]);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $treatment = $this->route('treatment');

        return [
            'performed_at' => ['required', 'date_format:'.ClinicTime::INPUT_FORMAT],
            'procedure_id' => [
                'required',
                'integer',
                // Só procedimentos ativos, ou o que o atendimento já usa.
                Rule::exists(Procedure::class, 'id')->where(fn ($query) => $query->where(
                    fn ($inner) => $inner->where('is_active', true)
                        ->when($treatment?->procedure_id, fn ($q, int $current) => $q->orWhere('id', $current)),
                )),
            ],
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

            // Agendamento que originou o atendimento: tem de ser deste paciente,
            // estar em aberto, sem outro atendimento e não ser de um dia futuro.
            'appointment_id' => $treatment instanceof Treatment ? ['prohibited'] : [
                'nullable',
                'integer',
                Rule::exists(Appointment::class, 'id')
                    ->where('patient_id', $this->route('patient')->getKey())
                    ->whereIn('status', array_map(fn (AppointmentStatus $status) => $status->value, AppointmentStatus::open()))
                    ->whereNull('treatment_id')
                    ->where(fn ($query) => $query->where('scheduled_at', '<=', ClinicTime::dayBounds(ClinicTime::today())[1])),
            ],

            'products' => ['nullable', 'array', 'max:'.self::MAX_PRODUCTS],
            'products.*.product_batch_id' => [
                'nullable',
                'integer',
                'distinct',
                // Lote vencido não pode ser escolhido.
                Rule::exists(ProductBatch::class, 'id')->where(
                    fn ($query) => $query->where(fn ($inner) => $inner->whereNull('expires_on')->orWhere('expires_on', '>=', ClinicTime::today())),
                ),
            ],
            'products.*.quantity' => ['nullable', 'required_with:products.*.product_batch_id', 'numeric', 'decimal:0,3', 'gt:0', 'max:999999'],
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
            'appointment_id.exists' => 'O agendamento informado não está mais em aberto. Volte ao Início e tente de novo.',
            'products.*.product_batch_id.exists' => 'Este lote não existe ou está vencido.',
            'products.*.product_batch_id.distinct' => 'Este lote está repetido na lista.',
            'products.*.quantity.required_with' => 'Informe a quantidade utilizada deste produto.',
        ];
    }
}
