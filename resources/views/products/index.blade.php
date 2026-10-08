@php
    use App\Enums\ProductCategory;
    use App\Support\LocalizedDecimal;
@endphp
<x-layouts.app title="Estoque">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold tracking-tight">Estoque</h1>
            <p class="mt-1 text-sm text-slate-600">{{ $products->total() }} {{ $products->total() === 1 ? 'produto' : 'produtos' }}</p>
        </div>
        @can('create', App\Models\Product::class)
            <a href="{{ route('products.create') }}" class="btn btn-primary">Novo produto</a>
        @endcan
    </div>

    <form method="GET" action="{{ route('products.index') }}" class="mt-6 grid grid-cols-1 gap-2 sm:grid-cols-12" role="search">
        <div class="sm:col-span-4">
            <label for="q" class="sr-only">Buscar por nome</label>
            <input id="q" name="q" type="search" value="{{ $filters['q'] }}" maxlength="100" class="input" placeholder="Buscar por nome">
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

    <div class="card mt-4 overflow-x-auto">
        <table class="w-full min-w-2xl text-left text-sm">
            <thead class="border-b border-slate-200 bg-slate-50 text-xs tracking-wide text-slate-500 uppercase">
                <tr>
                    <th scope="col" class="px-4 py-3 font-medium">Produto</th>
                    <th scope="col" class="px-4 py-3 font-medium">Categoria</th>
                    <th scope="col" class="px-4 py-3 text-right font-medium">Disponível</th>
                    <th scope="col" class="px-4 py-3 text-right font-medium">Mínimo</th>
                    <th scope="col" class="px-4 py-3 font-medium">Alertas</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($products as $product)
                    <tr class="hover:bg-slate-50">
                        <td class="px-4 py-3">
                            <a href="{{ route('products.show', $product) }}" class="font-medium text-teal-800 hover:underline">{{ $product->name }}</a>
                            @if ($product->brand)
                                <span class="block text-xs text-slate-500">{{ $product->brand }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-slate-600">{{ $product->category->label() }}</td>
                        <td class="px-4 py-3 text-right whitespace-nowrap tabular-nums">
                            {{ LocalizedDecimal::quantity($product->available_quantity ?? 0) }} {{ $product->unit->symbol() }}
                        </td>
                        <td class="px-4 py-3 text-right whitespace-nowrap text-slate-600 tabular-nums">
                            {{ (float) $product->minimum_stock > 0 ? LocalizedDecimal::quantity($product->minimum_stock).' '.$product->unit->symbol() : '—' }}
                        </td>
                        <td class="px-4 py-3">
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
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-10 text-center text-slate-500">Nenhum produto encontrado.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $products->links() }}
    </div>
</x-layouts.app>
