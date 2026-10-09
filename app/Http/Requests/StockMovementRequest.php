<?php

namespace App\Http\Requests;

use App\Enums\StockMovementType;
use App\Models\ProductBatch;
use App\Support\LocalizedDecimal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Saída, perda ou ajuste lançado à mão sobre um lote do produto. Todos
 * exigem motivo; o ajuste exige também a permissão de inventário.
 */
class StockMovementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can($this->type() === StockMovementType::Adjustment ? 'adjust' : 'move', $this->route('product'));
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['quantity' => LocalizedDecimal::normalize($this->input('quantity'))]);
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
            'reason' => ['required', 'string', 'max:255'],
        ];
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
