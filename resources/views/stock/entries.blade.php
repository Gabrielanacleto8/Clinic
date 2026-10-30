@php
    use App\Support\ClinicTime;
    use App\Support\LocalizedDecimal;

    $canEnter = auth()->user()->can('move', App\Models\Product::class);
    $canCorrectCost = auth()->user()->can('adjust', App\Models\Product::class);
    $filtered = array_filter($filters) !== [];
@endphp
<x-stock.page active="entradas" :alerts="$alerts" :subtitle="$entries->total().' '.($entries->total() === 1 ? 'entrada' : 'entradas')">
    @if ($canEnter)
        <x-slot:actions>
            <button type="button" class="btn btn-secondary" data-dialog-open="invoice-import">Importar nota fiscal</button>
            <button type="button" class="btn btn-primary" data-dialog-open="stock-entry">Nova entrada</button>
        </x-slot:actions>
    @endif

    <form method="GET" action="{{ route('stock.entries.index') }}" class="mt-4 grid grid-cols-1 gap-2 sm:grid-cols-12" role="search">
        <div class="sm:col-span-6">
            <label for="q" class="sr-only">Buscar por produto, lote, fornecedor ou nota fiscal</label>
            <input id="q" name="q" type="search" value="{{ $filters['q'] }}" maxlength="100" class="input" placeholder="Buscar por produto, lote, fornecedor ou nota fiscal">
        </div>
        @include('stock._period_fields', ['span' => 'sm:col-span-2'])
        <button type="submit" class="btn btn-secondary sm:col-span-2">Filtrar</button>
        @foreach (['q', 'de', 'ate'] as $field)
            @error($field)
                <p class="text-sm text-red-700 sm:col-span-12">{{ $message }}</p>
            @enderror
        @endforeach
    </form>

    <div class="card mt-4 relative overflow-x-auto">
        <table class="table-stack w-full min-w-5xl text-left text-sm">
            <thead class="border-b border-slate-200 bg-slate-50 text-xs tracking-wide text-slate-500 uppercase">
                <tr>
                    <th scope="col" class="px-4 py-3 font-medium">Data</th>
                    <th scope="col" class="px-4 py-3 font-medium">Produto</th>
                    <th scope="col" class="px-4 py-3 font-medium">Lote</th>
                    <th scope="col" class="px-4 py-3 font-medium">Fabricação</th>
                    <th scope="col" class="px-4 py-3 font-medium">Validade</th>
                    <th scope="col" class="px-4 py-3 text-right font-medium">Quantidade</th>
                    <th scope="col" class="px-4 py-3 text-right font-medium">Custo unit.</th>
                    <th scope="col" class="px-4 py-3 text-right font-medium">Valor</th>
                    <th scope="col" class="px-4 py-3 font-medium">Fornecedor</th>
                    <th scope="col" class="px-4 py-3 font-medium">Nota fiscal</th>
                    <th scope="col" class="px-4 py-3 font-medium">Usuário</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($entries as $entry)
                    @php $batch = $entry->batch; $product = $batch->product; @endphp
                    <tr class="hover:bg-slate-50">
                        <td data-label="Data" class="px-4 py-3 whitespace-nowrap text-slate-600 tabular-nums">{{ ClinicTime::format($entry->created_at) }}</td>
                        <td data-label="Produto" class="px-4 py-3">
                            <a href="{{ route('products.show', $product) }}" class="font-medium text-slate-800 hover:underline">{{ $product->name }}</a>
                            @if ($entry->reason)
                                <span class="block text-xs break-words text-slate-500">{{ $entry->reason }}</span>
                            @endif
                        </td>
                        <td data-label="Lote" class="px-4 py-3 tabular-nums">
                            <a href="{{ route('stock.batches.show', $batch) }}" class="text-slate-800 hover:underline">{{ $batch->batch_code }}</a>
                        </td>
                        <td data-label="Fabricação" class="px-4 py-3 whitespace-nowrap text-slate-600 tabular-nums">{{ $batch->manufactured_on?->format('d/m/Y') ?? '—' }}</td>
                        <td data-label="Validade" class="px-4 py-3 whitespace-nowrap text-slate-600 tabular-nums">{{ $batch->expires_on?->format('d/m/Y') ?? 'Sem validade' }}</td>
                        <td data-label="Quantidade" class="px-4 py-3 text-right font-medium whitespace-nowrap text-emerald-700 tabular-nums">
                            +{{ LocalizedDecimal::quantity($entry->quantity) }} {{ $product->unit->symbol() }}
                        </td>
                        <td data-label="Custo unit." class="px-4 py-3 text-right whitespace-nowrap tabular-nums">
                            {{ $entry->unit_cost !== null ? LocalizedDecimal::unitMoney($entry->unit_cost) : '—' }}
                            @if ($canCorrectCost)
                                <button type="button" class="block w-full cursor-pointer text-right text-xs text-slate-800 hover:underline" data-dialog-open="entry-cost"
                                        data-dialog-action="{{ route('stock.movements.cost', $entry) }}"
                                        data-dialog-summary="Entrada de {{ ClinicTime::format($entry->created_at) }} · {{ $product->name }} · lote {{ $batch->batch_code }}"
                                        data-dialog-values="{{ json_encode(['unit_cost' => LocalizedDecimal::unitMoneyInput($entry->unit_cost)]) }}">Corrigir custo</button>
                            @endif
                        </td>
                        <td data-label="Valor" class="px-4 py-3 text-right whitespace-nowrap text-slate-600 tabular-nums">{{ $entry->value() !== null ? LocalizedDecimal::money($entry->value()) : '—' }}</td>
                        <td data-label="Fornecedor" class="px-4 py-3 text-slate-600">{{ $entry->supplier ?: '—' }}</td>
                        <td data-label="Nota fiscal" class="px-4 py-3 text-slate-600 tabular-nums">{{ $entry->invoice_number ?: '—' }}</td>
                        <td data-label="Usuário" class="px-4 py-3 text-slate-600">{{ $entry->user?->name ?? '—' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="11" class="px-4 py-10 text-center text-slate-500">
                            Nenhuma entrada encontrada.
                            @if ($filtered)
                                <a href="{{ route('stock.entries.index') }}" class="font-medium text-slate-800 hover:underline">Limpar filtros</a>
                            @elseif ($canEnter)
                                <button type="button" class="cursor-pointer font-medium text-slate-800 hover:underline" data-dialog-open="stock-entry">Lançar a primeira entrada</button>
                            @endif
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $entries->links() }}
    </div>

    @if ($canEnter)
        {{-- "product" nulo de propósito: aqui o produto é escolhido no formulário. --}}
        @include('stock._entry_dialog', ['products' => $products, 'product' => null, 'autoshow' => request()->boolean('nova')])
        @include('stock._invoice_dialog')
    @endif
    @if ($canCorrectCost)
        @include('stock._entry_cost_dialog')
    @endif
</x-stock.page>
