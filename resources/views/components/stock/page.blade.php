@props(['active', 'alerts', 'subtitle' => null])
@php
    $tabs = [
        'produtos' => ['Produtos', route('products.index')],
        'entradas' => ['Entradas', route('stock.entries.index')],
        'movimentacoes' => ['Movimentações', route('stock.movements.index')],
        'lotes' => ['Lotes e validade', route('stock.batches.index')],
        'unidades' => ['Unidades abertas', route('stock.units.index')],
    ];

    $plural = fn (int $total, string $one, string $many) => $total.' '.($total === 1 ? $one : $many);

    // Alertas do estoque, cada um com a lista onde o problema é detalhado.
    $notices = array_filter([
        $alerts['low_stock'] > 0 ? [$plural($alerts['low_stock'], 'produto com estoque baixo', 'produtos com estoque baixo'), route('products.index', ['situacao' => 'baixo']), 'bg-red-100 text-red-800'] : null,
        $alerts['expired'] > 0 ? [$plural($alerts['expired'], 'lote vencido com saldo', 'lotes vencidos com saldo'), route('stock.batches.index', ['situacao' => 'vencidos']), 'bg-red-100 text-red-800'] : null,
        $alerts['units_to_discard'] > 0 ? [$plural($alerts['units_to_discard'], 'unidade aberta a descartar', 'unidades abertas a descartar'), route('stock.units.index'), 'bg-red-100 text-red-800'] : null,
        $alerts['expiring'] > 0 ? [$plural($alerts['expiring'], 'lote perto do vencimento', 'lotes perto do vencimento'), route('stock.batches.index', ['situacao' => 'vencendo']), 'bg-amber-100 text-amber-800'] : null,
    ]);

    $tabClass = fn (bool $current) => 'rounded-lg px-3 py-1.5 text-sm font-medium whitespace-nowrap '.($current ? 'bg-teal-700 text-white' : 'text-slate-700 hover:bg-slate-100');
@endphp
{{-- Moldura das abas do estoque: título, alertas e navegação. --}}
<x-layouts.app title="Estoque">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold tracking-tight">Estoque</h1>
            @if ($subtitle)
                <p class="mt-1 text-sm text-slate-600">{{ $subtitle }}</p>
            @endif
        </div>
        @isset($actions)
            <div class="flex flex-wrap items-center gap-2">{{ $actions }}</div>
        @endisset
    </div>

    @if ($notices !== [])
        <ul class="mt-4 flex flex-wrap gap-2" aria-label="Alertas do estoque">
            @foreach ($notices as [$text, $url, $classes])
                <li><a href="{{ $url }}" class="badge {{ $classes }} hover:underline">{{ $text }}</a></li>
            @endforeach
        </ul>
    @endif

    <nav class="mt-4 flex gap-1 relative overflow-x-auto rounded-xl border border-slate-200 bg-white p-1" aria-label="Seções do estoque">
        @foreach ($tabs as $key => [$label, $url])
            <a href="{{ $url }}" class="{{ $tabClass($active === $key) }}" @if ($active === $key) aria-current="page" @endif>{{ $label }}</a>
        @endforeach
    </nav>

    {{ $slot }}
</x-layouts.app>
