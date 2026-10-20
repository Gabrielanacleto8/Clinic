<?php

namespace App\Http\Requests;

use App\Enums\ProductCategory;
use App\Enums\ProductUnit;
use App\Models\Product;
use App\Models\StockUnit;
use App\Support\LocalizedDecimal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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
            'unit_content' => LocalizedDecimal::normalize($this->input('unit_content')),
            'multi_patient_use' => $this->boolean('multi_patient_use'),
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
            'code' => ['nullable', 'string', 'max:40', Rule::unique(Product::class)->ignore($this->route('product'))],
            'category' => ['required', Rule::enum(ProductCategory::class)],
            'brand' => ['nullable', 'string', 'max:100'],
            'manufacturer' => ['nullable', 'string', 'max:100'],
            'supplier' => ['nullable', 'string', 'max:150'],
            'unit' => ['required', Rule::enum(ProductUnit::class)],
            // Fracionado: conteúdo de cada unidade física, na unidade de medida.
            'unit_content' => ['nullable', 'numeric', 'decimal:0,3', 'gt:0', 'max:999999'],
            'hours_after_opening' => ['nullable', 'integer', 'min:1', 'max:8760'],
            'multi_patient_use' => ['required', 'boolean'],
            'minimum_stock' => ['required', 'numeric', 'decimal:0,3', 'min:0', 'max:999999'],
            'cost' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:999999.99'],
            'price' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:999999.99'],
            'is_active' => ['required', 'boolean'],
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

                // Deixar de ser fracionado largaria sem dono o que resta nas unidades abertas.
                if ($product instanceof Product && $product->isFractioned() && blank($this->input('unit_content'))
                    && StockUnit::query()->withBalance()->whereIn('product_batch_id', $product->batches()->select('id'))->exists()) {
                    $validator->errors()->add('unit_content', 'Há unidades abertas deste produto com saldo. Use ou descarte essas unidades antes de deixar de fracioná-lo.');
                }
            },
        ];
    }

    /**
     * Sem fracionamento, prazo depois de aberto e uso em vários pacientes não se aplicam.
     *
     * @return array<string, mixed>
     */
    public function productData(): array
    {
        $data = $this->validated();

        if (blank($data['unit_content'] ?? null)) {
            $data['hours_after_opening'] = null;
            $data['multi_patient_use'] = false;
        }

        return $data;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'nome',
            'code' => 'código',
            'category' => 'categoria',
            'brand' => 'marca',
            'manufacturer' => 'fabricante',
            'supplier' => 'fornecedor',
            'unit' => 'unidade de medida',
            'minimum_stock' => 'estoque mínimo',
            'unit_content' => 'conteúdo por unidade',
            'hours_after_opening' => 'prazo depois de aberto',
            'multi_patient_use' => 'uso da sobra em outro paciente',
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
        return [
            'name.unique' => 'Já existe um produto com este nome.',
            'code.unique' => 'Já existe um produto com este código.',
        ];
    }
}
