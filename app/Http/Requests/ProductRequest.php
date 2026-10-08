<?php

namespace App\Http\Requests;

use App\Enums\ProductCategory;
use App\Enums\ProductUnit;
use App\Models\Product;
use App\Support\LocalizedDecimal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Usado tanto no cadastro quanto na edição de produtos.
 */
class ProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        $product = $this->route('product');

        return $product instanceof Product
            ? $this->user()->can('update', $product)
            : $this->user()->can('create', Product::class);
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'minimum_stock' => LocalizedDecimal::normalize($this->input('minimum_stock')),
            'cost' => LocalizedDecimal::normalize($this->input('cost')),
            'price' => LocalizedDecimal::normalize($this->input('price')),
            // Checkbox desmarcado não é enviado pelo navegador.
            'is_active' => $this->boolean('is_active'),
        ]);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:150', Rule::unique(Product::class)->ignore($this->route('product'))],
            'category' => ['required', Rule::enum(ProductCategory::class)],
            'brand' => ['nullable', 'string', 'max:100'],
            'manufacturer' => ['nullable', 'string', 'max:100'],
            'supplier' => ['nullable', 'string', 'max:150'],
            'unit' => ['required', Rule::enum(ProductUnit::class)],
            'minimum_stock' => ['required', 'numeric', 'decimal:0,3', 'min:0', 'max:999999'],
            'cost' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:999999.99'],
            'price' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:999999.99'],
            'is_active' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'nome',
            'category' => 'categoria',
            'brand' => 'marca',
            'manufacturer' => 'fabricante',
            'supplier' => 'fornecedor',
            'unit' => 'unidade de medida',
            'minimum_stock' => 'estoque mínimo',
            'cost' => 'custo',
            'price' => 'preço',
            'is_active' => 'situação',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['name.unique' => 'Já existe um produto com este nome.'];
    }
}
