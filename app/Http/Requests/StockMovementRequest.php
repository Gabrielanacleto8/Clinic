<?php

namespace App\Http\Requests;

use App\Enums\StockMovementType;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Support\LocalizedDecimal;
use App\Support\Quantity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Saída, perda ou ajuste lançado à mão sobre um lote do produto. Todos
 * exigem motivo; o ajuste exige também a permissão de inventário. No
 * produto fracionado, valem só para o que está fechado: a tela pede
 * unidades fechadas (frascos) e o pedido as converte para a unidade de medida.
 */
class StockMovementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can($this->type() === StockMovementType::Adjustment ? 'adjust' : 'move', $this->route('product'));
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'quantity' => LocalizedDecimal::normalize($this->input('quantity')),
            'expected' => LocalizedDecimal::normalize($this->input('expected')),
        ]);
    }

    /**
     * Fracionado: a tela pede unidades fechadas (frascos); quantity() as converte.
     */
    private function inUnits(): bool
    {
        $product = $this->route('product');

        return $this->boolean('quantity_in_units') && $product instanceof Product && $product->isFractioned();
    }

    public function type(): ?StockMovementType
    {
        return StockMovementType::tryFrom((string) $this->input('type'));
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $isAdjustment = $this->type() === StockMovementType::Adjustment;

        return [
            'type' => ['required', Rule::in(array_map(fn (StockMovementType $type) => $type->value, StockMovementType::manual()))],
            'product_batch_id' => [
                'required',
                'integer',
                Rule::exists(ProductBatch::class, 'id')->where('product_id', $this->route('product')->getKey()),
            ],
            // No ajuste, é a quantidade contada (pode ser zero). Nos demais, a quantidade movimentada.
            'quantity' => ['required', 'numeric', 'decimal:0,3', $isAdjustment ? 'min:0' : 'gt:0', 'max:999999'],
            'quantity_in_units' => ['nullable', 'boolean'],
            // Ajuste: o saldo fechado que a tela mostrava, para recusar contagem feita sobre saldo que já mudou.
            'expected' => ['nullable', 'numeric', 'decimal:0,3', 'min:0'],
            'reason' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * @return list<callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $product = $this->route('product');
                $content = $product->isFractioned() ? Quantity::toThousandths($product->unit_content) : 0;

                if ($content === 0 || $validator->errors()->has('quantity')) {
                    return;
                }

                $typed = Quantity::toThousandths($this->input('quantity'));

                if ($this->inUnits() ? $typed % Quantity::SCALE === 0 : $typed % $content === 0) {
                    return;
                }

                $validator->errors()->add('quantity', $this->inUnits()
                    ? 'Informe um número inteiro de unidades fechadas (cada uma tem '.LocalizedDecimal::quantity($product->unit_content).' '.$product->unit->symbol().').'
                    : 'Este produto é fracionado: '
                        .($this->type() === StockMovementType::Adjustment ? 'conte só o que está fechado, ' : 'só o que está fechado sai por aqui, ')
                        .'em unidades inteiras de '.LocalizedDecimal::quantity($product->unit_content).' '.$product->unit->symbol()
                        .'. O que resta em unidade aberta sai pelo descarte da unidade.');
            },
        ];
    }

    /**
     * Quantidade na unidade de medida: o que veio em unidades fechadas é
     * multiplicado pelo conteúdo de cada unidade.
     */
    public function quantity(): string
    {
        $quantity = (string) $this->validated('quantity');

        if (! $this->inUnits()) {
            return $quantity;
        }

        $content = Quantity::toThousandths($this->route('product')->unit_content);

        return Quantity::fromThousandths((int) round(Quantity::toThousandths($quantity) / Quantity::SCALE) * $content);
    }

    /**
     * Saldo fechado que a tela mostrava ao contar, ou nulo quando não veio.
     */
    public function expected(): ?string
    {
        $expected = $this->validated('expected');

        return is_numeric($expected) ? (string) $expected : null;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'type' => 'tipo',
            'product_batch_id' => 'lote',
            'quantity' => 'quantidade',
            'reason' => 'motivo',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'quantity.gt' => 'A quantidade deve ser maior que zero.',
            'reason.required' => 'Informe o motivo.',
        ];
    }
}
