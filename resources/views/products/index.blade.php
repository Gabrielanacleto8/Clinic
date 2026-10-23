@php
    use App\Enums\ProductCategory;
    use App\Support\LocalizedDecimal;

    $canEdit = $products->contains(fn ($product) => auth()->user()->can('update', $product));
    $canCreate = auth()->user()->can('create', App\Models\Product::class);
@endphp
<x-stock.page active="produtos" :alerts="$alerts" :subtitle="$products->total().' '.($products->total() === 1 ? 'produto' : 'produtos')">
    @if ($canCreate)
        <x-slot:actions>
            <button type="button" class="btn btn-secondary" data-dialog-open="invoice-import">Importar nota fiscal</button>
            <button type="button" class="btn btn-primary" data-dialog-open="product-create">Novo produto</button>
        </x-slot:actions>
    @endif

    <form method="GET" action="{{ route('products.index') }}" class="mt-4 grid grid-cols-1 gap-2 sm:grid-cols-12" role="search">
        <div class="sm:col-span-4">
            <label for="q" class="sr-only">Buscar por nome ou código</label>
            <input id="q" name="q" type="search" value="{{ $filters['q'] }}" maxlength="100" class="input" placeholder="Buscar por nome ou código">
        </div>
        <div class="sm:col-span-3">
            <label for="categoria" class="sr-only">Categoria</label>
            <select id="categoria" name="categoria" class="input">
                <option value="">Todas as categorias</option>
                @foreach (ProductCategory::options() as $value => $label)
                    <option value="{{ $value }}" @selected($filters['categoria'] === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div class="sm:col-span-3">
            <label for="situacao" class="sr-only">Situação</label>
            <select id="situacao" name="situacao" class="input">
                @foreach ($statusOptions as $value => $label)
                    <option value="{{ $value }}" @selected($filters['situacao'] === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <button type="submit" class="btn btn-secondary sm:col-span-2">Filtrar</button>
        @foreach (['q', 'categoria', 'situacao'] as $field)
            @error($field)
                <p class="text-sm text-red-700 sm:col-span-12">{{ $message }}</p>
            @enderror
        @endforeach
    </form>

    <div class="card mt-4 relative overflow-x-auto">
        <table class="table-stack w-full min-w-2xl text-left text-sm">
            <thead class="border-b border-slate-200 bg-slate-50 text-xs tracking-wide text-slate-500 uppercase">
                <tr>
                    <th scope="col" class="px-4 py-3 font-medium">Produto</th>
                    <th scope="col" class="px-4 py-3 font-medium">Categoria</th>
                    <th scope="col" class="px-4 py-3 text-right font-medium">Disponível</th>
                    <th scope="col" class="px-4 py-3 text-right font-medium">Mínimo</th>
                    <th scope="col" class="px-4 py-3 font-medium">Alertas</th>
                    <th scope="col" class="px-4 py-3"><span class="sr-only">Ações</span></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($products as $product)
                    <tr class="hover:bg-slate-50">
                        <td class="px-4 py-3">
                            <a href="{{ route('products.show', $product) }}" class="font-medium text-teal-800 hover:underline">{{ $product->name }}</a>
                            @if ($product->code || $product->brand)
                                <span class="block text-xs text-slate-500">{{ implode(' · ', array_filter([$product->code, $product->brand])) }}</span>
                            @endif
                        </td>
                        <td data-label="Categoria" class="px-4 py-3 text-slate-600">{{ $product->category->label() }}</td>
                        <td data-label="Disponível" class="px-4 py-3 text-right whitespace-nowrap tabular-nums">
                            {{ LocalizedDecimal::quantity($product->available_quantity ?? 0) }} {{ $product->unit->symbol() }}
                        </td>
                        <td data-label="Mínimo" class="px-4 py-3 text-right whitespace-nowrap text-slate-600 tabular-nums">
                            {{ (float) $product->minimum_stock > 0 ? LocalizedDecimal::quantity($product->minimum_stock).' '.$product->unit->symbol() : '—' }}
                        </td>
                        @php $hasAlerts = ! $product->is_active || $product->isLowOnStock() || $product->expired_batches_count > 0 || $product->expiring_batches_count > 0; @endphp
                        <td data-label="Alertas" class="px-4 py-3" @if (! $hasAlerts) data-empty @endif>
                            <div class="flex flex-wrap gap-1">
                                @unless ($product->is_active)
                                    <span class="badge bg-slate-200 text-slate-700">Inativo</span>
                                @endunless
                                @if ($product->isLowOnStock())
                                    <span class="badge bg-red-100 text-red-800">Estoque baixo</span>
                                @endif
                                @if ($product->expired_batches_count > 0)
                                    <span class="badge bg-red-100 text-red-800">Lote vencido</span>
                                @endif
                                @if ($product->expiring_batches_count > 0)
                                    <span class="badge bg-amber-100 text-amber-800">Vencendo</span>
                                @endif
                            </div>
                        </td>
                        <td class="px-4 py-3 text-right whitespace-nowrap">
                            @can('update', $product)
                                @php
                                    // Dados desta linha, que o botão coloca no diálogo de edição (ver app.js).
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
                                        'cost' => LocalizedDecimal::moneyInput($product->cost),
                                        'price' => LocalizedDecimal::moneyInput($product->price),
                                        'is_active' => $product->is_active,
                                    ];
                                @endphp
                                <button type="button" class="cursor-pointer font-medium text-teal-800 hover:underline" data-dialog-open="product-edit"
                                        data-dialog-action="{{ route('products.update', $product) }}"
                                        data-dialog-summary="{{ $product->name }}"
                                        data-dialog-values="{{ json_encode($values) }}">Editar</button>
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-10 text-center text-slate-500">Nenhum produto encontrado.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $products->links() }}
    </div>

    @if ($canEdit)
        {{-- Um só diálogo para a página: o botão "Editar" de cada linha o preenche.
             $editing só existe quando o formulário volta com erro de validação. --}}
        <x-dialog id="product-edit" title="Editar produto" size="lg">
            <p class="text-sm font-medium text-slate-700" data-dialog-summary>{{ $editing?->name }}</p>
            <form method="POST" action="{{ $editing ? route('products.update', $editing) : '' }}" class="mt-4 space-y-5" novalidate>
                @method('PUT')
                <input type="hidden" name="_dialog" value="product-edit">
                <input type="hidden" name="product_id" value="{{ $editing?->id }}">
                @include('products._form', ['product' => $editing ?? new App\Models\Product, 'scope' => 'product-edit', 'submitLabel' => 'Salvar alterações'])
            </form>
        </x-dialog>
    @endif

    @if ($canCreate)
        <x-dialog id="product-create" title="Novo produto" size="lg">
            <form method="POST" action="{{ route('products.store') }}" class="space-y-5" novalidate>
                <input type="hidden" name="_dialog" value="product-create">
                @include('products._form', [
                    'product' => new App\Models\Product(['is_active' => true, 'minimum_stock' => 0]),
                    'scope' => 'product-create',
                    'submitLabel' => 'Cadastrar produto',
                ])
            </form>
        </x-dialog>

        @include('stock._invoice_dialog')
    @endif
</x-stock.page>
