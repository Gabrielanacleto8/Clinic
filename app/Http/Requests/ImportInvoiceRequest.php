<?php

namespace App\Http\Requests;

use App\Enums\ProductCategory;
use App\Enums\ProductUnit;
use App\Models\Product;
use App\Services\InvoiceXmlReader;
use App\Support\ClinicTime;
use App\Support\LocalizedDecimal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Conferência dos itens de uma nota fiscal importada. Só os itens marcados
 * para importar são validados e gravados.
 */
class ImportInvoiceRequest extends FormRequest
{
    private const MAX_LOTS = 20;

    public function authorize(): bool
    {
        return $this->user()->can('create', Product::class) && $this->user()->can('move', Product::class);
    }

    protected function prepareForValidation(): void
    {
        if (! is_array($this->input('items'))) {
            return;
        }

        // Valores digitados no padrão brasileiro.
        $this->merge(['items' => array_map(function ($item) {
            if (! is_array($item)) {
                return $item;
            }

            $item['unit_cost'] = LocalizedDecimal::normalize($item['unit_cost'] ?? null);
            $item['lots'] = array_map(
                fn ($lot) => is_array($lot) ? [...$lot, 'quantity' => LocalizedDecimal::normalize($lot['quantity'] ?? null)] : $lot,
                is_array($item['lots'] ?? null) ? $item['lots'] : [],
            );

            return $item;
        }, $this->input('items'))]);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $today = ClinicTime::today();
        $rules = ['items' => ['required', 'array', 'max:'.InvoiceXmlReader::MAX_ITEMS]];

        foreach ($this->selectedItems() as $index => $item) {
            $prefix = "items.{$index}";
            $isNew = blank($item['product_id'] ?? null);

            $rules += [
                "{$prefix}.product_id" => ['nullable', 'integer', Rule::exists(Product::class, 'id')->where('is_active', true)],
                // Dados do cadastro: só valem quando o item vira um produto novo.
                "{$prefix}.name" => $isNew ? ['required', 'string', 'min:2', 'max:150', Rule::unique(Product::class, 'name')] : ['nullable'],
                "{$prefix}.code" => $isNew ? ['nullable', 'string', 'max:40', Rule::unique(Product::class, 'code')] : ['nullable'],
                "{$prefix}.category" => $isNew ? ['required', Rule::enum(ProductCategory::class)] : ['nullable'],
                "{$prefix}.unit" => $isNew ? ['required', Rule::enum(ProductUnit::class)] : ['nullable'],
                "{$prefix}.unit_cost" => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:999999.99'],
                "{$prefix}.enter" => ['required', 'boolean'],
            ];

            if (! ($item['enter'] ?? false)) {
                continue;
            }

            $rules["{$prefix}.lots"] = ['required', 'array', 'max:'.self::MAX_LOTS];

            foreach ($item['lots'] ?? [] as $lotIndex => $lot) {
                $lotPrefix = "{$prefix}.lots.{$lotIndex}";

                $rules += [
                    "{$lotPrefix}.batch_code" => ['required', 'string', 'max:60'],
                    "{$lotPrefix}.quantity" => ['required', 'numeric', 'decimal:0,3', 'gt:0', 'max:999999'],
                    "{$lotPrefix}.manufactured_on" => ['nullable', 'date_format:Y-m-d', 'after:2000-01-01', 'before_or_equal:'.$today],
                    "{$lotPrefix}.expires_on" => [
                        'nullable', 'date_format:Y-m-d', 'after_or_equal:'.$today, 'before:2100-01-01',
                        ...(filled($lot['manufactured_on'] ?? null) ? ["after:{$lotPrefix}.manufactured_on"] : []),
                    ],
                ];
            }
        }

        return $rules;
    }

    /**
     * @return list<callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (is_array($this->input('items')) && $this->selectedItems() === []) {
                    $validator->errors()->add('items', 'Marque pelo menos um item da nota para importar.');
                }
            },
        ];
    }

    /**
     * Itens marcados para importar, com o índice que têm no formulário.
     *
     * @return array<int|string, array<string, mixed>>
     */
    private function selectedItems(): array
    {
        return array_filter(
            is_array($this->input('items')) ? $this->input('items') : [],
            fn ($item) => is_array($item) && filter_var($item['import'] ?? false, FILTER_VALIDATE_BOOLEAN),
        );
    }

    /**
     * Itens conferidos, prontos para o importador.
     *
     * @return array<int, array<string, mixed>>
     */
    public function rows(): array
    {
        return collect($this->validated('items'))
            ->map(fn (array $item) => [
                ...$item,
                'product_id' => filled($item['product_id'] ?? null) ? (int) $item['product_id'] : null,
                'unit_cost' => filled($item['unit_cost'] ?? null) ? (string) $item['unit_cost'] : null,
                'enter' => (bool) $item['enter'],
                'lots' => $item['lots'] ?? [],
            ])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'items.*.product_id' => 'produto',
            'items.*.name' => 'nome',
            'items.*.code' => 'código',
            'items.*.category' => 'categoria',
            'items.*.unit' => 'unidade',
            'items.*.unit_cost' => 'custo unitário',
            'items.*.lots.*.batch_code' => 'lote',
            'items.*.lots.*.quantity' => 'quantidade',
            'items.*.lots.*.manufactured_on' => 'fabricação',
            'items.*.lots.*.expires_on' => 'validade',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'items.*.name.unique' => 'Já existe um produto com este nome. Escolha-o na lista de produtos.',
            'items.*.code.unique' => 'Já existe um produto com este código. Escolha-o na lista de produtos.',
            'items.*.product_id.exists' => 'Escolha um produto ativo.',
            'items.*.lots.*.batch_code.required' => 'Informe o lote, ou desmarque a entrada de estoque deste item.',
            'items.*.lots.*.quantity.gt' => 'A quantidade deve ser maior que zero.',
            'items.*.lots.*.manufactured_on.before_or_equal' => 'A fabricação não pode estar no futuro.',
            'items.*.lots.*.expires_on.after_or_equal' => 'Não é possível dar entrada em um lote já vencido.',
            'items.*.lots.*.expires_on.after' => 'A validade deve ser posterior à fabricação.',
        ];
    }
}
