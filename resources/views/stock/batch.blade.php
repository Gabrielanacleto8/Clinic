@php
    use App\Enums\StockMovementType;
    use App\Support\LocalizedDecimal;

    $unit = $product->unit->symbol();
    $sum = fn (StockMovementType $type) => LocalizedDecimal::quantity(abs((float) ($totals[$type->value] ?? 0))).' '.$unit;
    $batchOptions = [$batch->id => $batch->batch_code.' · saldo '.LocalizedDecimal::quantity($batch->quantity).' '.$unit];
    $preset = json_encode(['product_batch_id' => $batch->id]);
@endphp
<x-layouts.app :title="'Lote '.$batch->batch_code">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <a href="{{ route('stock.batches.index') }}" class="text-sm text-slate-600 hover:underline">← Lotes e validade</a>
            <h1 class="mt-1 text-xl font-semibold tracking-tight">Lote {{ $batch->batch_code }}</h1>
            <p class="mt-1 text-sm text-slate-600">
                <a href="{{ route('products.show', $product) }}" class="text-teal-800 hover:underline">{{ $product->name }}</a>
                @unless ($product->is_active)
                    <span class="badge ml-1 bg-slate-200 text-slate-700">Inativo</span>
                @endunless
            </p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            @can('move', $product)
                <button type="button" class="btn btn-secondary" data-dialog-open="stock-exit" data-dialog-values="{{ $preset }}">Saída</button>
                <button type="button" class="btn btn-secondary" data-dialog-open="stock-loss" data-dialog-values="{{ $preset }}">Perda</button>
            @endcan
            @can('adjust', $product)
                <button type="button" class="btn btn-secondary" data-dialog-open="stock-adjustment" data-dialog-values="{{ $preset }}">Ajuste</button>
            @endcan
        </div>
    </div>

    <dl class="mt-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
        <div class="card px-4 py-3">
            <dt class="text-xs text-slate-500">Saldo</dt>
            <dd class="mt-0.5 text-lg font-semibold tabular-nums">{{ LocalizedDecimal::quantity($batch->quantity) }} {{ $unit }}</dd>
        </div>
        <div class="card px-4 py-3">
            <dt class="text-xs text-slate-500">Validade</dt>
            <dd class="mt-0.5 text-lg font-semibold tabular-nums">
                {{ $batch->expires_on?->format('d/m/Y') ?? 'Sem validade' }}
                @include('stock._expiry_badge', ['batch' => $batch])
            </dd>
        </div>
        <div class="card px-4 py-3">
            <dt class="text-xs text-slate-500">Fabricação</dt>
            <dd class="mt-0.5 text-lg font-semibold tabular-nums">{{ $batch->manufactured_on?->format('d/m/Y') ?? '—' }}</dd>
        </div>
        <div class="card px-4 py-3">
            <dt class="text-xs text-slate-500">Usado em atendimentos</dt>
            <dd class="mt-0.5 text-lg font-semibold tabular-nums">{{ $sum(StockMovementType::Consumption) }}</dd>
        </div>
    </dl>

    <p class="mt-3 text-sm text-slate-600">
        Entradas: {{ $sum(StockMovementType::Entry) }} · Saídas: {{ $sum(StockMovementType::Exit) }} · Perdas: {{ $sum(StockMovementType::Loss) }}
    </p>

    <section class="card mt-6 relative overflow-x-auto" aria-labelledby="rastreabilidade">
        <h2 id="rastreabilidade" class="px-4 pt-5 pb-3 text-sm font-semibold tracking-wide text-slate-500 uppercase">Rastreabilidade do lote</h2>
        @if ($movements->isEmpty())
            <p class="px-4 pb-6 text-sm text-slate-600">Nenhuma movimentação registrada.</p>
        @else
            @include('stock._movements_table', ['movements' => $movements, 'product' => $product, 'batch' => $batch])
        @endif
    </section>

    <div class="mt-4">
        {{ $movements->links() }}
    </div>

    @include('stock._movement_dialogs', ['product' => $product, 'batchOptions' => $batchOptions])
</x-layouts.app>
