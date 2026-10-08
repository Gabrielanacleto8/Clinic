<?php

namespace App\Http\Requests;

use App\Enums\StockMovementType;
use App\Models\ProductBatch;
use App\Support\ClinicTime;
use App\Support\LocalizedDecimal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StockMovementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('move', $this->route('product'));
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
        $isEntry = $this->type() === StockMovementType::Entry;
        $isAdjustment = $this->type() === StockMovementType::Adjustment;

        return [
            'type' => ['required', Rule::enum(StockMovementType::class)],

            // Entrada: informa o lote pelo código. Se ainda não existir, é criado.
            'batch_code' => $isEntry ? ['required', 'string', 'max:60'] : ['prohibited'],
            'expires_on' => $isEntry ? ['nullable', 'date_format:Y-m-d', 'after_or_equal:'.ClinicTime::today(), 'before:2100-01-01'] : ['prohibited'],

            // Saída e ajuste: escolhem um lote já existente deste produto.
            'product_batch_id' => $isEntry ? ['prohibited'] : [
                'required',
                'integer',
                Rule::exists(ProductBatch::class, 'id')->where('product_id', $this->route('product')->getKey()),
            ],

            // No ajuste, é a quantidade contada (pode ser zero). Nos demais, a quantidade movimentada.
            'quantity' => ['required', 'numeric', 'decimal:0,3', $isAdjustment ? 'min:0' : 'gt:0', 'max:999999'],
            'reason' => [$isAdjustment ? 'required' : 'nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'type' => 'tipo',
            'batch_code' => 'lote',
            'expires_on' => 'validade',
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
            'expires_on.after_or_equal' => 'Não é possível dar entrada em um lote já vencido.',
            'quantity.gt' => 'A quantidade deve ser maior que zero.',
            'reason.required' => 'Informe o motivo do ajuste.',
        ];
    }
}
