<?php

namespace App\Http\Requests;

use App\Models\Patient;
use App\Models\Procedure;
use App\Models\TreatmentPlan;
use App\Services\DiscountLimits;
use App\Support\LocalizedDecimal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Orçamento em aberto: paciente (só ao criar), validade, observações e os
 * procedimentos com sessões e valor.
 */
class TreatmentPlanRequest extends FormRequest
{
    public const MAX_ITEMS = 15;

    public const MAX_SESSIONS = 50;

    public function authorize(): bool
    {
        // Ao criar, o acesso a pacientes é conferido antes da validação: a resposta
        // de "paciente inexistente" não pode revelar IDs a quem não vê pacientes.
        return $this->isUpdate()
            ? $this->user()->can('update', $this->route('treatmentPlan'))
            : $this->user()->can('create', TreatmentPlan::class) && $this->user()->can('viewAny', Patient::class);
    }

    private function isUpdate(): bool
    {
        return $this->route('treatmentPlan') instanceof TreatmentPlan;
    }

    protected function prepareForValidation(): void
    {
        if (is_array($this->input('items'))) {
            $this->merge([
                'items' => array_map(
                    fn ($row) => is_array($row) ? [...$row, 'unit_price' => LocalizedDecimal::normalize($row['unit_price'] ?? null)] : $row,
                    $this->input('items'),
                ),
            ]);
        }
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            // O paciente é escolhido só ao criar; editar não troca o paciente.
            'patient_id' => $this->isUpdate()
                ? ['prohibited']
                : ['required', 'integer', Rule::exists(Patient::class, 'id')->withoutTrashed()],
            // Botão "Criar e converter em venda".
            'convert' => [$this->isUpdate() ? 'prohibited' : 'nullable', 'boolean'],
            'valid_until' => ['nullable', 'date_format:Y-m-d', 'after:2000-01-01', 'before:2100-01-01'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'items' => ['required', 'array', 'max:'.self::MAX_ITEMS],
            'items.*.procedure_id' => ['nullable', 'integer', Rule::exists(Procedure::class, 'id')->where('is_active', true)],
            'items.*.sessions' => ['nullable', 'required_with:items.*.procedure_id', 'integer', 'min:1', 'max:'.self::MAX_SESSIONS],
            'items.*.unit_price' => ['nullable', 'required_with:items.*.procedure_id', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999.99'],
        ];
    }

    /**
     * @return list<callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->has('items')) {
                    return;
                }

                $procedureIds = collect($this->input('items'))->pluck('procedure_id')->filter(filled(...));

                if ($procedureIds->isEmpty()) {
                    $validator->errors()->add('items', 'Inclua ao menos um procedimento.');
                }

                // Um procedimento por linha: as sessões somam na mesma linha.
                if ($procedureIds->count() !== $procedureIds->unique()->count()) {
                    $validator->errors()->add('items', 'Cada procedimento entra uma vez só; some as sessões na mesma linha.');
                }

                // Desconto sobre o preço de tabela, somando os itens, dentro do limite do perfil de quem orça.
                if (! $validator->errors()->has('items*')) {
                    [$list, $charged] = $this->totals();
                    // Pacote sem valor é cortesia, como o atendimento sem cobrança: fica fora do limite.
                    $violation = $charged > 0 ? app(DiscountLimits::class)->violation($this->user(), $list, $charged) : null;

                    if ($violation !== null) {
                        $validator->errors()->add('items', $violation);
                    }
                }
            },
        ];
    }

    /**
     * Paciente escolhido no formulário; arquivado já foi recusado na validação.
     */
    public function patient(): Patient
    {
        return Patient::query()->findOrFail((int) $this->validated('patient_id'));
    }

    public function convertsToSale(): bool
    {
        return (bool) $this->validated('convert');
    }

    /**
     * @return array{valid_until: ?string, notes: ?string}
     */
    public function planData(): array
    {
        return ['valid_until' => $this->validated('valid_until'), 'notes' => $this->validated('notes')];
    }

    /**
     * Linhas preenchidas, sem as linhas em branco do formulário, com o preço
     * de tabela de cada procedimento (o desconto do orçamento é a diferença).
     *
     * @return list<array{procedure_id: int, sessions: int, unit_price: string, list_price: string}>
     */
    public function items(): array
    {
        $prices = $this->catalogPrices();

        return collect($this->validated('items'))
            ->filter(fn (array $row) => filled($row['procedure_id'] ?? null))
            ->map(fn (array $row) => [
                'procedure_id' => (int) $row['procedure_id'],
                'sessions' => (int) $row['sessions'],
                'unit_price' => number_format((float) $row['unit_price'], 2, '.', ''),
                'list_price' => number_format($prices[(int) $row['procedure_id']] ?? (float) $row['unit_price'], 2, '.', ''),
            ])
            ->values()
            ->all();
    }

    /**
     * Total pelo preço de tabela e total cobrado, das linhas preenchidas.
     *
     * @return array{float, float}
     */
    private function totals(): array
    {
        $prices = $this->catalogPrices();
        $list = 0.0;
        $charged = 0.0;

        foreach ($this->input('items') as $row) {
            if (! is_array($row) || ! filled($row['procedure_id'] ?? null)) {
                continue;
            }

            $sessions = (int) $row['sessions'];
            $price = (float) $row['unit_price'];
            $list += $sessions * ($prices[(int) $row['procedure_id']] ?? $price);
            $charged += $sessions * $price;
        }

        return [round($list, 2), round($charged, 2)];
    }

    /**
     * @var array<int, float>|null
     */
    private ?array $catalogPrices = null;

    /**
     * Preço de catálogo dos procedimentos do formulário, numa consulta.
     *
     * @return array<int, float>
     */
    private function catalogPrices(): array
    {
        $ids = collect($this->input('items'))->pluck('procedure_id')->filter(fn ($id) => is_numeric($id))->map(fn ($id) => (int) $id)->all();

        return $this->catalogPrices ??= Procedure::query()->whereKey($ids)->pluck('price', 'id')->map(fn ($price) => (float) $price)->all();
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'patient_id' => 'paciente',
            'valid_until' => 'validade',
            'notes' => 'observações',
            'items' => 'procedimentos',
            'items.*.procedure_id' => 'procedimento',
            'items.*.sessions' => 'sessões',
            'items.*.unit_price' => 'valor por sessão',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'patient_id.required' => 'Busque e selecione um paciente na lista.',
            'patient_id.exists' => 'Busque e selecione um paciente na lista.',
            'items.*.procedure_id.exists' => 'Este procedimento não existe ou está inativo.',
            'items.*.sessions.required_with' => 'Informe quantas sessões.',
            'items.*.unit_price.required_with' => 'Informe o valor de cada sessão.',
            'items.*.unit_price.numeric' => 'Informe o valor em reais, como 450,00.',
        ];
    }
}
