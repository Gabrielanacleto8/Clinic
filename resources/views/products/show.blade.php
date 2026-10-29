@php
    use App\Support\LocalizedDecimal;

    $unit = $product->unit->symbol();
    // Lote vencido não entra nos diálogos de saída e ajuste: só sai como perda, pelo botão da linha.
    $batchOptions = $batches->reject(fn ($batch) => $batch->isExpired())->mapWithKeys(fn ($batch) => [
        $batch->id => $batch->batch_code.($product->isFractioned()
            ? ' · fechado '.LocalizedDecimal::quantity($batch->sealedQuantity()).' '.$unit
            : ' · saldo '.LocalizedDecimal::quantity($batch->quantity).' '.$unit),
    ])->all();
@endphp
<x-layouts.app :title="$product->name">
    <a href="{{ route('products.index') }}" class="text-sm text-slate-600 hover:underline">← Estoque</a>
    <div class="mt-1">
        @include('products._details')
    </div>

    <section class="card mt-6 relative overflow-x-auto" aria-labelledby="movimentacoes">
        <h2 id="movimentacoes" class="px-4 pt-5 pb-3 text-sm font-semibold tracking-wide text-slate-500 uppercase sm:px-6">Histórico de movimentações</h2>
        @if ($movements->isEmpty())
            <p class="px-4 pt-1 pb-6 text-sm text-slate-600 sm:px-6">Nenhuma movimentação registrada.</p>
        @else
            {{-- "batch" nulo de propósito: a variável do laço de lotes não pode fixar a coluna. --}}
            @include('stock._movements_table', ['movements' => $movements, 'product' => $product, 'batch' => null])
        @endif
    </section>

    <div class="mt-4">
        {{ $movements->links() }}
    </div>

    @if (auth()->user()->can('move', $product) && $product->is_active)
        @include('stock._entry_dialog', ['product' => $product, 'batches' => $batches])
    @endif
    @include('stock._movement_dialogs', ['product' => $product, 'batchOptions' => $batchOptions])
    @if (auth()->user()->can('discard', $product) && $batches->contains(fn ($batch) => $batch->isExpired() && (float) $batch->quantity > 0))
        @include('stock._write_off_dialog')
    @endif
</x-layouts.app>
