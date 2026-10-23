<?php

namespace App\Http\Requests;

use App\Enums\StockMovementType;
use App\Models\StockMovement;
use App\Support\LocalizedDecimal;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Correção do custo unitário de uma entrada digitado errado. O custo médio
 * do lote sai das entradas, por isso exige a permissão de ajuste.
 */
class UpdateEntryCostRequest extends FormRequest
{
    public function authorize(): bool
    {
        $movement = $this->route('movement');

        return $movement instanceof StockMovement
            && $movement->type === StockMovementType::Entry
            && $this->user()->can('adjust', $movement->batch->product);
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['unit_cost' => LocalizedDecimal::normalize($this->input('unit_cost'))]);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'unit_cost' => ['nullable', 'numeric', 'decimal:0,4', 'min:0', 'max:999999.9999'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['unit_cost' => 'custo unitário'];
    }
}
