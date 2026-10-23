<?php

namespace App\Http\Requests;

use App\Models\Product;
use App\Models\ProductBatch;
use App\Support\LocalizedDecimal;
use App\Support\Quantity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Contagem de inventário: a quantidade encontrada em cada lote conferido. No
 * produto fracionado, conta-se só o que está fechado, em unidades inteiras
 * (frascos), que o pedido converte para a unidade de medida. Cada linha leva
 * também o saldo que a tela mostrava, para a contagem ser recusada se o lote
 * se moveu nesse meio-tempo.
 */
class StockInventoryRequest extends FormRequest
{
    public const PER_PAGE = 50;

    public function authorize(): bool
    {
        return $this->user()->can('adjust', Product::class);
    }

    /**
     * @var array<int, string> Conteúdo da unidade dos lotes contados em unidades fechadas (in_units).
     */
    private array $contents = [];

    protected function prepareForValidation(): void
    {
        if (! is_array($this->input('counted'))) {
            return;
        }

        // Quantidades digitadas no padrão brasileiro.
        $this->merge(['counted' => array_map(LocalizedDecimal::normalize(...), $this->input('counted'))]);
    }

    private function countedInUnits(int $batchId): bool
    {
        $flags = is_array($this->input('in_units')) ? $this->input('in_units') : [];

        return filter_var($flags[$batchId] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:255'],
            'counted' => ['required', 'array', 'max:'.self::PER_PAGE],
            // Linha em branco é lote não conferido: fica como está.
            'counted.*' => ['nullable', 'numeric', 'decimal:0,3', 'min:0', 'max:999999'],
            // Saldo fechado que a tela mostrava para cada lote.
            'expected' => ['nullable', 'array'],
            'expected.*' => ['nullable', 'numeric', 'decimal:0,3', 'min:0'],
            'in_units' => ['nullable', 'array'],
            'in_units.*' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return list<callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $counts = collect(is_array($this->input('counted')) ? $this->input('counted') : [])
                    ->filter(fn ($quantity, $batchId) => is_numeric($quantity) && is_numeric($batchId) && ! $validator->errors()->has("counted.{$batchId}"));

                if ($counts->isEmpty()) {
                    return;
                }

                $batches = ProductBatch::query()
                    ->with('product:id,unit,unit_content')
                    ->whereKey($counts->keys()->map(fn ($id) => (int) $id)->all())
                    ->get(['id', 'product_id']);

                foreach ($batches as $batch) {
                    $product = $batch->product;

                    if (! $product->isFractioned()) {
                        continue;
                    }

                    // Fracionado: a tela pede unidades fechadas (frascos) inteiras; counts() as converte.
                    if ($this->countedInUnits($batch->id)) {
                        $this->contents[$batch->id] = (string) $product->unit_content;

                        if (Quantity::toThousandths($counts[$batch->id]) % Quantity::SCALE !== 0) {
                            $validator->errors()->add("counted.{$batch->id}", 'Informe um número inteiro de unidades fechadas (cada uma tem '
                                .LocalizedDecimal::quantity($product->unit_content).' '.$product->unit->symbol().').');
                        }
                    } elseif (Quantity::toThousandths($counts[$batch->id]) % Quantity::toThousandths($product->unit_content) !== 0) {
                        $validator->errors()->add("counted.{$batch->id}", 'Conte só o fechado, em unidades inteiras de '
                            .LocalizedDecimal::quantity($product->unit_content).' '.$product->unit->symbol().'.');
                    }
                }
            },
        ];
    }

    /**
     * Contagens preenchidas, por id de lote, na unidade de medida: o que foi
     * contado em unidades fechadas vem multiplicado pelo conteúdo da unidade.
     *
     * @return array<int, string>
     */
    public function counts(): array
    {
        return collect($this->validated('counted'))
            ->filter(fn ($quantity) => filled($quantity))
            ->mapWithKeys(fn ($quantity, $batchId) => [(int) $batchId => isset($this->contents[(int) $batchId])
                ? Quantity::fromThousandths((int) round(Quantity::toThousandths($quantity) / Quantity::SCALE) * Quantity::toThousandths($this->contents[(int) $batchId]))
                : (string) $quantity])
            ->all();
    }

    /**
     * Saldo fechado que a tela mostrava para o lote, ou nulo se não veio.
     */
    public function expectedFor(int $batchId): ?string
    {
        $expected = $this->validated('expected')[$batchId] ?? null;

        return is_numeric($expected) ? (string) $expected : null;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['reason' => 'motivo', 'counted' => 'contagem', 'counted.*' => 'quantidade contada', 'expected.*' => 'saldo exibido'];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['reason.required' => 'Informe o motivo do inventário.'];
    }
}
