<?php

namespace App\Http\Requests;

use App\Enums\ProcedureCategory;
use App\Models\Procedure;
use App\Models\Product;
use App\Support\Duration;
use App\Support\LocalizedDecimal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Usado tanto no cadastro quanto na edição de procedimentos.
 */
class ProcedureRequest extends FormRequest
{
    public const MAX_PRODUCTS = 10;

    public function authorize(): bool
    {
        $procedure = $this->route('procedure');

        return $procedure instanceof Procedure
            ? $this->user()->can('update', $procedure)
            : $this->user()->can('create', Procedure::class);
    }

    protected function prepareForValidation(): void
    {
        // Quantidades estimadas digitadas no padrão brasileiro.
        $products = collect($this->input('products'))
            ->map(fn ($row) => is_array($row) ? [...$row, 'quantity' => LocalizedDecimal::normalize($row['quantity'] ?? null)] : $row)
            ->all();

        $this->merge([
            'products' => is_array($this->input('products')) ? $products : $this->input('products'),
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
            'name' => ['required', 'string', 'min:2', 'max:150', Rule::unique(Procedure::class)->ignore($this->route('procedure'))],
            'description' => ['nullable', 'string', 'max:2000'],
            'consent_template' => ['nullable', 'string', 'max:20000'],
            'category' => ['required', Rule::enum(ProcedureCategory::class)],
            'price' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:999999.99'],
            'duration_minutes' => ['required', 'integer', Rule::in(Duration::OPTIONS)],
            'is_active' => ['required', 'boolean'],

            'products' => ['nullable', 'array', 'max:'.self::MAX_PRODUCTS],
            'products.*.product_id' => ['nullable', 'integer', 'distinct', Rule::exists(Product::class, 'id')],
            'products.*.quantity' => ['nullable', 'required_with:products.*.product_id', 'numeric', 'decimal:0,3', 'gt:0', 'max:999999'],
        ];
    }

    /**
     * Dados do procedimento, sem as linhas de produtos.
     *
     * @return array<string, mixed>
     */
    public function procedureData(): array
    {
        return $this->safe()->except(['products']);
    }

    /**
     * Produtos preenchidos no formato aceito por sync(), já sem as linhas em branco.
     *
     * @return array<int, array{quantity: string}>
     */
    public function typicalProducts(): array
    {
        return collect($this->validated('products') ?? [])
            ->filter(fn (array $row) => filled($row['product_id'] ?? null))
            ->mapWithKeys(fn (array $row) => [(int) $row['product_id'] => ['quantity' => $row['quantity']]])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'nome',
            'description' => 'descrição',
            'consent_template' => 'termo de consentimento',
            'category' => 'categoria',
            'price' => 'valor',
            'duration_minutes' => 'duração estimada',
            'is_active' => 'situação',
            'products' => 'produtos',
            'products.*.product_id' => 'produto',
            'products.*.quantity' => 'quantidade estimada',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.unique' => 'Já existe um procedimento com este nome.',
            'price.numeric' => 'Informe um valor em reais, como 850,00.',
            'price.decimal' => 'O valor aceita no máximo duas casas decimais.',
            'products.*.product_id.distinct' => 'Este produto está repetido na lista.',
            'products.*.quantity.required_with' => 'Informe a quantidade estimada deste produto.',
        ];
    }
}
