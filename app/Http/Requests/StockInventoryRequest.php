<?php

namespace App\Http\Requests;

use App\Models\Product;
use App\Support\LocalizedDecimal;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Contagem de inventário: a quantidade encontrada em cada lote conferido.
 */
class StockInventoryRequest extends FormRequest
{
    public const PER_PAGE = 50;

    public function authorize(): bool
    {
        return $this->user()->can('adjust', Product::class);
    }

    protected function prepareForValidation(): void
    {
        if (! is_array($this->input('counted'))) {
            return;
        }

        // Quantidades digitadas no padrão brasileiro.
        $this->merge(['counted' => array_map(LocalizedDecimal::normalize(...), $this->input('counted'))]);
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
        ];
    }

    /**
     * Contagens preenchidas, por id de lote.
     *
     * @return array<int, string>
     */
    public function counts(): array
    {
        return collect($this->validated('counted'))
            ->filter(fn ($quantity) => filled($quantity))
            ->mapWithKeys(fn ($quantity, $batchId) => [(int) $batchId => (string) $quantity])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['reason' => 'motivo', 'counted' => 'contagem', 'counted.*' => 'quantidade contada'];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['reason.required' => 'Informe o motivo do inventário.'];
    }
}
