<?php

namespace App\Http\Requests;

use App\Enums\ProductCategory;
use App\Enums\ProductUnit;
use App\Models\Product;
use App\Models\StockInvoice;
use App\Services\InvoiceXmlReader;
use App\Services\PendingInvoiceImports;
use App\Services\StockInvoiceImporter;
use App\Support\ClinicTime;
use App\Support\LocalizedDecimal;
use App\Support\Quantity;
use Closure;
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

    private ?bool $alreadyImported = null;

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

            $item['total_cost'] = LocalizedDecimal::normalize($item['total_cost'] ?? null);
            $item['unit_content'] = LocalizedDecimal::normalize($item['unit_content'] ?? null);
            $item['multi_patient_use'] = filter_var($item['multi_patient_use'] ?? false, FILTER_VALIDATE_BOOLEAN);
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
        // Nota já importada: nada do formulário importa, só a recusa.
        if ($this->alreadyImported()) {
            return ['importacao' => [fn (string $attribute, mixed $value, Closure $fail) => $fail(StockInvoiceImporter::ALREADY_IMPORTED)]];
        }

        $today = ClinicTime::today();
        $rules = [
            // Identifica a nota em conferência na sessão: duas abas, duas notas.
            'importacao' => ['required', 'string', 'size:32'],
            'items' => ['required', 'array', 'max:'.InvoiceXmlReader::MAX_ITEMS],
        ];

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
                // Fracionado no produto novo: conteúdo de cada frasco, na unidade escolhida.
                "{$prefix}.unit_content" => $isNew ? ['nullable', 'numeric', 'decimal:0,3', 'gt:0', 'max:999999'] : ['nullable'],
                "{$prefix}.hours_after_opening" => $isNew ? ['nullable', 'integer', 'min:1', 'max:8760'] : ['nullable'],
                "{$prefix}.multi_patient_use" => ['nullable', 'boolean'],
                "{$prefix}.total_cost" => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999.99'],
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
        if ($this->alreadyImported()) {
            return [];
        }

        return [
            function (Validator $validator): void {
                if (is_array($this->input('items')) && $this->selectedItems() === []) {
                    $validator->errors()->add('items', 'Marque pelo menos um item da nota para importar.');
                }
            },
            $this->checkFractionedLots(...),
            $this->checkNewFractionedLots(...),
            $this->checkRepeatedCodes(...),
        ];
    }

    /**
     * Produto novo com conteúdo por frasco: a quantidade dos lotes é a da
     * nota, em frascos inteiros; o importador multiplica pelo conteúdo.
     */
    private function checkNewFractionedLots(Validator $validator): void
    {
        foreach ($this->selectedItems() as $index => $item) {
            if (filled($item['product_id'] ?? null) || ! is_numeric($item['unit_content'] ?? null) || ! filter_var($item['enter'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                continue;
            }

            foreach ($item['lots'] ?? [] as $lotIndex => $lot) {
                $field = "items.{$index}.lots.{$lotIndex}.quantity";

                if (is_array($lot) && is_numeric($lot['quantity'] ?? null) && ! $validator->errors()->has($field)
                    && Quantity::toThousandths($lot['quantity']) % Quantity::SCALE !== 0) {
                    $validator->errors()->add($field, 'Com o conteúdo por unidade preenchido, a quantidade do lote é em unidades fechadas (frascos) inteiras.');
                }
            }
        }
    }

    /**
     * Produto fracionado entra em unidades físicas inteiras, como na entrada manual.
     */
    private function checkFractionedLots(Validator $validator): void
    {
        $entering = array_filter(
            $this->selectedItems(),
            fn (array $item) => filled($item['product_id'] ?? null) && is_numeric($item['product_id']) && filter_var($item['enter'] ?? false, FILTER_VALIDATE_BOOLEAN),
        );

        if ($entering === []) {
            return;
        }

        $products = Product::query()
            ->whereKey(array_map(fn (array $item) => (int) $item['product_id'], $entering))
            ->whereNotNull('unit_content')
            ->get(['id', 'unit', 'unit_content'])
            ->keyBy('id');

        foreach ($entering as $index => $item) {
            $product = $products->get((int) $item['product_id']);
            $content = $product ? Quantity::toThousandths($product->unit_content) : 0;

            foreach ($content > 0 ? ($item['lots'] ?? []) : [] as $lotIndex => $lot) {
                $field = "items.{$index}.lots.{$lotIndex}.quantity";

                if (is_array($lot) && is_numeric($lot['quantity'] ?? null) && ! $validator->errors()->has($field)
                    && Quantity::toThousandths($lot['quantity']) % $content !== 0) {
                    $validator->errors()->add($field, 'Este produto é fracionado: informe a quantidade na unidade do estoque, em unidades inteiras de '
                        .LocalizedDecimal::quantity($product->unit_content).' '.$product->unit->symbol().'.');
                }
            }
        }
    }

    /**
     * Dois produtos novos com o mesmo código e nomes diferentes esbarrariam no
     * índice único do código ao gravar.
     */
    private function checkRepeatedCodes(Validator $validator): void
    {
        $seen = [];

        foreach ($this->selectedItems() as $index => $item) {
            if (filled($item['product_id'] ?? null) || blank($item['code'] ?? null) || ! is_string($item['code'])) {
                continue;
            }

            $code = mb_strtolower(trim($item['code']));
            $name = mb_strtolower(trim((string) ($item['name'] ?? '')));

            if (isset($seen[$code]) && $seen[$code] !== $name) {
                $validator->errors()->add("items.{$index}.code", 'Outro item novo desta nota já usa este código. Use códigos diferentes ou o mesmo nome.');
            }

            $seen[$code] ??= $name;
        }
    }

    private function alreadyImported(): bool
    {
        $accessKey = app(PendingInvoiceImports::class)->get($this->session(), $this->input('importacao'))['access_key'] ?? null;

        return $this->alreadyImported ??= $accessKey !== null && StockInvoice::query()->where('access_key', $accessKey)->exists();
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
                'total_cost' => filled($item['total_cost'] ?? null) ? (string) $item['total_cost'] : null,
                'unit_content' => filled($item['unit_content'] ?? null) ? (string) $item['unit_content'] : null,
                'hours_after_opening' => filled($item['hours_after_opening'] ?? null) ? (int) $item['hours_after_opening'] : null,
                'multi_patient_use' => (bool) ($item['multi_patient_use'] ?? false),
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
            'items.*.total_cost' => 'valor total do item',
            'items.*.unit_content' => 'conteúdo por unidade',
            'items.*.hours_after_opening' => 'prazo depois de aberto',
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
            'items.*.name.unique' => 'Já existe um produto com este nome. Escolha-o na lista de produtos ou, se ele estiver inativo, reative-o no cadastro.',
            'items.*.code.unique' => 'Já existe um produto com este código. Escolha-o na lista de produtos ou, se ele estiver inativo, reative-o no cadastro.',
            'items.*.product_id.exists' => 'Escolha um produto ativo.',
            'items.*.lots.*.batch_code.required' => 'Informe o lote, ou desmarque a entrada de estoque deste item.',
            'items.*.lots.*.quantity.gt' => 'A quantidade deve ser maior que zero.',
            'items.*.lots.*.manufactured_on.before_or_equal' => 'A fabricação não pode estar no futuro.',
            'items.*.lots.*.expires_on.after_or_equal' => 'Não é possível dar entrada em um lote já vencido.',
            'items.*.lots.*.expires_on.after' => 'A validade deve ser posterior à fabricação.',
            'importacao.required' => 'A importação expirou. Envie o XML da nota fiscal de novo.',
            'importacao.string' => 'A importação expirou. Envie o XML da nota fiscal de novo.',
            'importacao.size' => 'A importação expirou. Envie o XML da nota fiscal de novo.',
        ];
    }
}
