@php use App\Support\LocalizedDecimal; @endphp
@csrf

<section class="card p-6">
    <div class="grid grid-cols-1 gap-4 md:grid-cols-6">
        <x-form.input class="md:col-span-4" name="name" label="Nome" :value="$product->name" required maxlength="150" autocomplete="off" />
        <x-form.select class="md:col-span-2" name="category" label="Categoria" :options="App\Enums\ProductCategory::options()"
                       :selected="$product->category?->value" required placeholder="Selecione" />

        <x-form.input class="md:col-span-2" name="brand" label="Marca" :value="$product->brand" maxlength="100" autocomplete="off" />
        <x-form.input class="md:col-span-2" name="manufacturer" label="Fabricante" :value="$product->manufacturer" maxlength="100" autocomplete="off" />
        <x-form.input class="md:col-span-2" name="supplier" label="Fornecedor" :value="$product->supplier" maxlength="150" autocomplete="off" />

        <x-form.select class="md:col-span-2" name="unit" label="Unidade de medida" :options="App\Enums\ProductUnit::options()"
                       :selected="$product->unit?->value" required placeholder="Selecione" />
        <x-form.input class="md:col-span-2" name="minimum_stock" label="Estoque mínimo" :value="LocalizedDecimal::quantity($product->minimum_stock ?? 0)"
                      required inputmode="decimal" autocomplete="off" hint="Zero desliga o alerta de estoque baixo." />
        <div class="md:col-span-2 md:pt-7">
            <label class="flex items-center gap-2 text-sm">
                <input type="hidden" name="is_active" value="0">
                <input type="checkbox" name="is_active" value="1" class="size-4 rounded border-slate-300 accent-teal-700"
                       @checked(old('is_active', $product->is_active))>
                Ativo
            </label>
        </div>

        <x-form.input class="md:col-span-2" name="cost" label="Custo unitário (R$)" :value="LocalizedDecimal::moneyInput($product->cost)"
                      inputmode="decimal" autocomplete="off" placeholder="0,00" />
        <x-form.input class="md:col-span-2" name="price" label="Preço unitário (R$)" :value="LocalizedDecimal::moneyInput($product->price)"
                      inputmode="decimal" autocomplete="off" placeholder="0,00" />
    </div>
    <p class="mt-4 text-xs text-slate-500">Quantidade, lote e validade são informados nas entradas de estoque, na página do produto.</p>
</section>

<div class="flex flex-wrap items-center justify-end gap-3">
    <a href="{{ $product->exists ? route('products.show', $product) : route('products.index') }}" class="btn btn-secondary">Cancelar</a>
    <button type="submit" class="btn btn-primary">{{ $product->exists ? 'Salvar alterações' : 'Cadastrar produto' }}</button>
</div>
