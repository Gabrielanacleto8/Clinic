@php
    use App\Enums\StockMovementType;
@endphp
<x-stock.page active="movimentacoes" :alerts="$alerts" :subtitle="$movements->total().' '.($movements->total() === 1 ? 'movimentação' : 'movimentações')">
    <form method="GET" action="{{ route('stock.movements.index') }}" class="mt-4 grid grid-cols-1 gap-2 sm:grid-cols-12" role="search">
        <div class="sm:col-span-4">
            <label for="q" class="sr-only">Buscar por produto, lote, fornecedor ou nota fiscal</label>
            <input id="q" name="q" type="search" value="{{ $filters['q'] }}" maxlength="100" class="input" placeholder="Buscar por produto ou lote">
        </div>
        <div class="sm:col-span-2">
            <label for="tipo" class="sr-only">Tipo</label>
            <select id="tipo" name="tipo" class="input">
                <option value="">Todos os tipos</option>
                @foreach (StockMovementType::options() as $value => $label)
                    <option value="{{ $value }}" @selected($filters['tipo'] === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        @include('stock._period_fields', ['span' => 'sm:col-span-2'])
        <button type="submit" class="btn btn-secondary sm:col-span-2">Filtrar</button>
        @foreach (['q', 'tipo', 'de', 'ate'] as $field)
            @error($field)
                <p class="text-sm text-red-700 sm:col-span-12">{{ $message }}</p>
            @enderror
        @endforeach
    </form>

    <div class="card mt-4 relative overflow-x-auto">
        @if ($movements->isEmpty())
            <p class="px-4 py-10 text-center text-sm text-slate-500">Nenhuma movimentação encontrada.</p>
        @else
            @include('stock._movements_table', ['movements' => $movements, 'product' => null, 'batch' => null])
        @endif
    </div>

    <p class="mt-3 text-xs text-slate-500">O histórico é permanente: uma movimentação não é alterada nem excluída. Um erro se corrige com um novo lançamento.</p>

    <div class="mt-4">
        {{ $movements->links() }}
    </div>
</x-stock.page>
