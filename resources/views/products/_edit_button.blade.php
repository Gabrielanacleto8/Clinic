@php
    use App\Support\LocalizedDecimal;

    // Dados do produto, que o botão coloca no diálogo de edição da listagem (ver app.js).
    $values = [
        'product_id' => $product->id,
        'name' => $product->name,
        'code' => $product->code,
        'category' => $product->category->value,
        'brand' => $product->brand,
        'manufacturer' => $product->manufacturer,
        'supplier' => $product->supplier,
        'unit' => $product->unit->value,
        'minimum_stock' => LocalizedDecimal::quantityInput($product->minimum_stock),
        'unit_content' => LocalizedDecimal::quantityInput($product->unit_content),
        'hours_after_opening' => $product->hours_after_opening,
        'multi_patient_use' => $product->multi_patient_use,
        'cost' => LocalizedDecimal::unitMoneyInput($product->cost),
        'is_active' => $product->is_active,
    ];
@endphp
<button type="button" class="{{ $class }}" data-dialog-open="product-edit"
        data-dialog-action="{{ route('products.update', $product) }}"
        data-dialog-summary="{{ $product->name }}"
        data-dialog-values="{{ json_encode($values) }}">Editar</button>
