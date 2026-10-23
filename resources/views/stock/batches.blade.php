@php
    use App\Support\LocalizedDecimal;

    $canDiscard = auth()->user()->can('discard', App\Models\Product::class);
    $filtered = array_filter($filters) !== [] && $filters !== ['q' => '', 'situacao' => 'com-saldo', 'vence_ate' => ''];
@endphp
<x-stock.page active="lotes" :alerts="$alerts" :subtitle="$batches->total().' '.($batches->total() === 1 ? 'lote' : 'lotes')">
    @can('adjust', App\Models\Product::class)
        <x-slot:actions>
            <a href="{{ route('stock.inventory.edit') }}" class="btn btn-secondary">Inventário</a>
        </x-slot:actions>
    @endcan

    <form method="GET" action="{{ route('stock.batches.index') }}" class="mt-4 grid grid-cols-1 gap-2 sm:grid-cols-12" role="search">
        <div class="sm:col-span-4">
            <label for="q" class="sr-only">Buscar por produto ou lote</label>
            <input id="q" name="q" type="search" value="{{ $filters['q'] }}" maxlength="100" class="input" placeholder="Buscar por produto ou lote">
        </div>
        <div class="sm:col-span-3">
            <label for="situacao" class="sr-only">Situação</label>
            <select id="situacao" name="situacao" class="input">
                @foreach ($statusOptions as $value => $label)
                    <option value="{{ $value }}" @selected($filters['situacao'] === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div class="flex items-center gap-2 sm:col-span-3">
            <label for="vence_ate" class="shrink-0 text-sm text-slate-600">Vence até</label>
            <input id="vence_ate" name="vence_ate" type="date" value="{{ $filters['vence_ate'] }}" class="input min-w-0">
        </div>
        <button type="submit" class="btn btn-secondary sm:col-span-2">Filtrar</button>
        @foreach (['q', 'situacao', 'vence_ate'] as $field)
            @error($field)
                <p class="text-sm text-red-700 sm:col-span-12">{{ $message }}</p>
            @enderror
        @endforeach
    </form>

    <div class="card mt-4 relative overflow-x-auto">
        <table class="table-stack w-full min-w-3xl text-left text-sm">
            <thead class="border-b border-slate-200 bg-slate-50 text-xs tracking-wide text-slate-500 uppercase">
                <tr>
                    <th scope="col" class="px-4 py-3 font-medium">Lote</th>
                    <th scope="col" class="px-4 py-3 font-medium">Produto</th>
                    <th scope="col" class="px-4 py-3 font-medium">Fabricação</th>
                    <th scope="col" class="px-4 py-3 font-medium">Validade</th>
                    <th scope="col" class="px-4 py-3 text-right font-medium">Saldo</th>
                    <th scope="col" class="px-4 py-3"><span class="sr-only">Ações</span></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($batches as $batch)
                    @php $product = $batch->product; $empty = (float) $batch->quantity === 0.0; @endphp
                    <tr @class(['hover:bg-slate-50', 'text-slate-400' => $empty])>
                        <td data-label="Lote" class="px-4 py-3 font-medium tabular-nums">
                            <a href="{{ route('stock.batches.show', $batch) }}" class="text-teal-800 hover:underline">{{ $batch->batch_code }}</a>
                        </td>
                        <td data-label="Produto" class="px-4 py-3">
                            <a href="{{ route('products.show', $product) }}" class="hover:underline">{{ $product->name }}</a>
                            @unless ($product->is_active)
                                <span class="badge ml-1 bg-slate-200 text-slate-700">Inativo</span>
                            @endunless
                        </td>
                        <td data-label="Fabricação" class="px-4 py-3 whitespace-nowrap tabular-nums">{{ $batch->manufactured_on?->format('d/m/Y') ?? '—' }}</td>
                        <td data-label="Validade" class="px-4 py-3 whitespace-nowrap tabular-nums">
                            {{ $batch->expires_on?->format('d/m/Y') ?? 'Sem validade' }}
                            @include('stock._expiry_badge', ['batch' => $batch])
                        </td>
                        <td data-label="Saldo" class="px-4 py-3 text-right whitespace-nowrap tabular-nums">{{ LocalizedDecimal::quantity($batch->quantity) }} {{ $product->unit->symbol() }}</td>
                        <td class="px-4 py-3 text-right whitespace-nowrap">
                            @if ($canDiscard && ! $empty && $batch->isExpired())
                                <button type="button" class="cursor-pointer font-medium text-red-700 hover:underline" data-dialog-open="batch-write-off"
                                        data-dialog-action="{{ route('stock.batches.write-off', $batch) }}"
                                        data-dialog-summary="{{ $product->name }} · lote {{ $batch->batch_code }} · {{ LocalizedDecimal::quantity($batch->quantity) }} {{ $product->unit->symbol() }}"
                                        data-dialog-values="{{ json_encode(['reason' => 'Vencido em '.$batch->expires_on->format('d/m/Y')], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}">Baixar vencido</button>
                            @else
                                <a href="{{ route('stock.batches.show', $batch) }}" class="font-medium text-teal-800 hover:underline">Rastrear</a>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-10 text-center text-slate-500">
                            Nenhum lote encontrado.
                            @if ($filtered)
                                <a href="{{ route('stock.batches.index') }}" class="font-medium text-teal-800 hover:underline">Limpar filtros</a>
                            @endif
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $batches->links() }}
    </div>

    @if ($canDiscard)
        @include('stock._write_off_dialog')
    @endif
</x-stock.page>
