@php
    use App\Enums\StockMovementType;
    use App\Support\LocalizedDecimal;

    $filtered = array_filter($filters) !== [];
    $consumption = $summary[StockMovementType::Consumption->value] ?? null;
@endphp
<x-stock.page active="movimentacoes" :alerts="$alerts" :subtitle="$movements->total().' '.($movements->total() === 1 ? 'movimentação' : 'movimentações')">
    <form method="GET" action="{{ route('stock.movements.index') }}" class="mt-4 grid grid-cols-1 gap-2 sm:grid-cols-12" role="search">
        <div class="sm:col-span-4">
            <label for="q" class="sr-only">Buscar por produto, lote, fornecedor, nota fiscal ou motivo</label>
            <input id="q" name="q" type="search" value="{{ $filters['q'] }}" maxlength="100" class="input" placeholder="Produto, lote, fornecedor, NF ou motivo">
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

    @if ($summary !== [])
        {{-- Totais por tipo dentro dos filtros: entrada pelo custo da compra, o resto pelo custo médio do lote na baixa. --}}
        <dl class="mt-4 grid grid-cols-2 gap-3 md:grid-cols-3 lg:grid-cols-6" aria-label="Resumo das movimentações filtradas">
            @foreach (StockMovementType::cases() as $type)
                @continue(! isset($summary[$type->value]))
                @php $row = $summary[$type->value]; $net = $type === StockMovementType::Consumption && isset($row['net_value']); @endphp
                <div class="card px-4 py-3">
                    <dt class="text-xs text-slate-500">{{ $type->label() }}{{ $net ? ' (líquido)' : '' }}</dt>
                    <dd @class(['mt-0.5 text-lg font-semibold tabular-nums', 'text-red-700' => $type === StockMovementType::Loss])>
                        {{ LocalizedDecimal::money($net ? $row['net_value'] : $row['value']) }}
                    </dd>
                    <dd class="text-xs text-slate-500">
                        {{ $row['count'] }} {{ $row['count'] === 1 ? 'lançamento' : 'lançamentos' }}
                        @if ($net)
                            · descontados os estornos de atendimentos anulados
                        @endif
                    </dd>
                </div>
            @endforeach
        </dl>
    @endif

    <div class="card mt-4 relative overflow-x-auto">
        @if ($movements->isEmpty())
            <p class="px-4 py-10 text-center text-sm text-slate-500">
                Nenhuma movimentação encontrada.
                @if ($filtered)
                    <a href="{{ route('stock.movements.index') }}" class="font-medium text-teal-800 hover:underline">Limpar filtros</a>
                @endif
            </p>
        @else
            @include('stock._movements_table', ['movements' => $movements, 'product' => null, 'batch' => null])
        @endif
    </div>

    <p class="mt-3 text-xs text-slate-500">O histórico é permanente: uma movimentação não é alterada nem excluída. Um erro de quantidade se corrige com um novo lançamento; só o custo de uma entrada pode ser corrigido, com registro na auditoria.</p>

    <div class="mt-4">
        {{ $movements->links() }}
    </div>
</x-stock.page>
