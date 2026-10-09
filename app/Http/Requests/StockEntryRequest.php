<?php

namespace App\Http\Requests;

use App\Models\Product;
use App\Support\ClinicTime;
use App\Support\LocalizedDecimal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'unit_cost' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:999999.99'],
            'supplier' => ['nullable', 'string', 'max:150'],
            'invoice_number' => ['nullable', 'string', 'max:60'],
            'reason' => ['nullable', 'string', 'max:255'],
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
            'reason' => 'observação',
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
