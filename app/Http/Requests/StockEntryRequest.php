<?php

namespace App\Http\Requests;

use App\Models\Product;
use App\Support\ClinicTime;
use App\Support\LocalizedDecimal;
use App\Support\Quantity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Entrada manual de estoque: o que chegou, em que lote e com que dados de compra.
 */
class StockEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('move', Product::class);
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'quantity' => LocalizedDecimal::normalize($this->input('quantity')),
            'unit_cost' => LocalizedDecimal::normalize($this->input('unit_cost')),
        ]);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $today = ClinicTime::today();

        return [
            'product_id' => ['required', 'integer', Rule::exists(Product::class, 'id')->where('is_active', true)],
            // Um código novo cria o lote; um existente soma ao saldo dele.
            'batch_code' => ['required', 'string', 'max:60'],
            'manufactured_on' => ['nullable', 'date_format:Y-m-d', 'after:2000-01-01', 'before_or_equal:'.$today],
            'expires_on' => [
                'nullable', 'date_format:Y-m-d', 'after_or_equal:'.$today, 'before:2100-01-01',
                ...($this->filled('manufactured_on') ? ['after:manufactured_on'] : []),
            ],
            'quantity' => ['required', 'numeric', 'decimal:0,3', 'gt:0', 'max:999999'],
            // Até quatro casas: insumo barato custa frações de centavo por unidade.
            'unit_cost' => ['nullable', 'numeric', 'decimal:0,4', 'min:0', 'max:999999.9999'],
            'supplier' => ['nullable', 'string', 'max:150'],
            'invoice_number' => ['nullable', 'string', 'max:60'],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return list<callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->hasAny(['product_id', 'quantity'])) {
                    return;
                }

                // Produto fracionado entra em unidades físicas inteiras, na unidade de medida.
                $product = Product::query()->find((int) $this->input('product_id'), ['id', 'unit', 'unit_content']);
                $content = $product?->isFractioned() ? Quantity::toThousandths($product->unit_content) : 0;

                if ($content > 0 && Quantity::toThousandths($this->input('quantity')) % $content !== 0) {
                    $validator->errors()->add('quantity', 'Este produto é fracionado: informe unidades inteiras de '
                        .LocalizedDecimal::quantity($product->unit_content).' '.$product->unit->symbol().' (ex.: 3 unidades = '
                        .LocalizedDecimal::quantity(3 * (float) $product->unit_content).' '.$product->unit->symbol().').');
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
            'product_id' => 'produto',
            'batch_code' => 'lote',
            'manufactured_on' => 'fabricação',
            'expires_on' => 'validade',
            'quantity' => 'quantidade',
            'unit_cost' => 'custo unitário',
            'supplier' => 'fornecedor',
            'invoice_number' => 'nota fiscal',
            'reason' => 'motivo',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'product_id.exists' => 'Escolha um produto ativo.',
            'manufactured_on.before_or_equal' => 'A fabricação não pode estar no futuro.',
            'expires_on.after_or_equal' => 'Não é possível dar entrada em um lote já vencido.',
            'expires_on.after' => 'A validade deve ser posterior à fabricação.',
            'quantity.gt' => 'A quantidade deve ser maior que zero.',
        ];
    }
}
