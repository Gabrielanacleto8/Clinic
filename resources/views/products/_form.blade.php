@php
    use App\Support\LocalizedDecimal;

    // Na listagem do estoque o formulário abre em um diálogo ($scope é o id dele).
    $scope ??= null;
    $inScope = $scope === null || old('_dialog') === $scope;
    // Dois diálogos na mesma página (cadastro e edição) não podem repetir ids.
    $id = fn (string $field) => $scope ? $scope.'-'.$field : $field;
@endphp
@csrf

<section @class(['card p-6' => ! $scope])>
    <div class="grid grid-cols-1 gap-4 md:grid-cols-6">
        <x-form.input :scope="$scope" class="md:col-span-4" :id="$id('name')" name="name" label="Nome" :value="$product->name" required maxlength="150" autocomplete="off" />
        <x-form.input :scope="$scope" class="md:col-span-2" :id="$id('code')" name="code" label="Código" :value="$product->code" maxlength="40" autocomplete="off"
                      hint="Código interno ou de barras." />

        <x-form.select :scope="$scope" class="md:col-span-2" :id="$id('category')" name="category" label="Categoria" :options="App\Enums\ProductCategory::options()"
                       :selected="$product->category?->value" required placeholder="Selecione" />
        <x-form.input :scope="$scope" class="md:col-span-2" :id="$id('brand')" name="brand" label="Marca" :value="$product->brand" maxlength="100" autocomplete="off" />
        <x-form.input :scope="$scope" class="md:col-span-2" :id="$id('manufacturer')" name="manufacturer" label="Fabricante" :value="$product->manufacturer" maxlength="100" autocomplete="off" />

        <x-form.input :scope="$scope" class="md:col-span-2" :id="$id('supplier')" name="supplier" label="Fornecedor" :value="$product->supplier" maxlength="150" autocomplete="off" />
        <x-form.select :scope="$scope" class="md:col-span-2" :id="$id('unit')" name="unit" label="Unidade de medida" :options="App\Enums\ProductUnit::options()"
                       :selected="$product->unit?->value" required placeholder="Selecione" />
        <x-form.input :scope="$scope" class="md:col-span-2" :id="$id('minimum_stock')" name="minimum_stock" label="Estoque mínimo" :value="LocalizedDecimal::quantity($product->minimum_stock ?? 0)"
                      required inputmode="decimal" autocomplete="off" hint="Zero desliga o alerta de estoque baixo." />

        <x-form.input :scope="$scope" class="md:col-span-2" :id="$id('cost')" name="cost" label="Custo unitário (R$)" :value="LocalizedDecimal::moneyInput($product->cost)"
                      inputmode="decimal" autocomplete="off" placeholder="0,00" />
        <x-form.input :scope="$scope" class="md:col-span-2" :id="$id('price')" name="price" label="Preço unitário (R$)" :value="LocalizedDecimal::moneyInput($product->price)"
                      inputmode="decimal" autocomplete="off" placeholder="0,00" />
        <div class="md:col-span-2 md:pt-7">
            <label class="flex items-center gap-2 text-sm">
                <input type="hidden" name="is_active" value="0">
                <input type="checkbox" name="is_active" value="1" class="size-4 rounded border-slate-300 accent-teal-700"
                       @checked($inScope ? old('is_active', $product->is_active) : $product->is_active)>
                Ativo
            </label>
        </div>
    </div>
    <p class="mt-4 text-xs text-slate-500">Quantidade, lote e validade são informados nas entradas de estoque.</p>
</section>

<div class="flex flex-wrap items-center justify-end gap-3">
    @if ($scope)
        <button type="button" class="btn btn-secondary" data-dialog-close>Cancelar</button>
    @else
        <a href="{{ $product->exists ? route('products.show', $product) : route('products.index') }}" class="btn btn-secondary">Cancelar</a>
    @endif
    <button type="submit" class="btn btn-primary">{{ $submitLabel ?? ($product->exists ? 'Salvar alterações' : 'Cadastrar produto') }}</button>
</div>
