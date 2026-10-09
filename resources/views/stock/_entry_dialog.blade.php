@php
    use App\Support\ClinicTime;

    // $product é sempre informado: fixo na página de um produto, nulo na aba
    // Entradas, onde o produto é escolhido em $products.
    $scope = 'stock-entry';
@endphp
{{-- Diálogo de entrada manual de estoque. --}}
<x-dialog :id="$scope" title="Nova entrada de estoque" size="lg">
    <form method="POST" action="{{ route('stock.entries.store') }}" novalidate>
        @csrf
        <input type="hidden" name="_dialog" value="{{ $scope }}">

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            @if ($product)
                <input type="hidden" name="product_id" value="{{ $product->id }}">
            @else
                <x-form.select :scope="$scope" class="sm:col-span-2" id="entry-product" name="product_id" label="Produto" required placeholder="Selecione"
                               :options="$products->mapWithKeys(fn ($option) => [$option->id => $option->name.($option->code ? ' · '.$option->code : '').' ('.$option->unit->symbol().')'])->all()" />
            @endif

            <x-form.input :scope="$scope" id="entry-batch" name="batch_code" label="Lote" required maxlength="60" autocomplete="off"
                          :list="$product ? 'lotes-existentes' : null" hint="Um código novo cria o lote. Um código existente soma ao saldo dele." />
            @if ($product)
                <datalist id="lotes-existentes">
                    @foreach ($batches as $existing)
                        <option value="{{ $existing->batch_code }}"></option>
                    @endforeach
                </datalist>
            @endif
            <x-form.input :scope="$scope" id="entry-quantity" name="quantity" :label="'Quantidade'.($product ? ' ('.$product->unit->symbol().')' : '')" required inputmode="decimal" autocomplete="off" />

            <x-form.input :scope="$scope" id="entry-manufactured" name="manufactured_on" label="Fabricação" type="date" :max="ClinicTime::today()" />
            <x-form.input :scope="$scope" id="entry-expires" name="expires_on" label="Validade" type="date" :min="ClinicTime::today()" />

            <x-form.input :scope="$scope" id="entry-cost" name="unit_cost" label="Custo unitário (R$)" inputmode="decimal" autocomplete="off" placeholder="0,00" />
            <x-form.input :scope="$scope" id="entry-invoice" name="invoice_number" label="Nota fiscal" maxlength="60" autocomplete="off" />

            <x-form.input :scope="$scope" class="sm:col-span-2" id="entry-supplier" name="supplier" label="Fornecedor" maxlength="150" autocomplete="off" :value="$product?->supplier" />
            <x-form.input :scope="$scope" class="sm:col-span-2" id="entry-reason" name="reason" label="Observação" maxlength="255" autocomplete="off" />
        </div>

        <div class="mt-5 flex justify-end gap-2">
            <button type="button" class="btn btn-secondary" data-dialog-close>Cancelar</button>
            <button type="submit" class="btn btn-primary">Registrar entrada</button>
        </div>
    </form>
</x-dialog>
