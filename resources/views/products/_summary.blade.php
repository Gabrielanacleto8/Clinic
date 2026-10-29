{{-- Conteúdo da janela do produto na listagem, buscado por app.js ao clicar na linha. Sem layout. --}}
<div data-summary-content>
    @include('products._details', ['inList' => true])

    <section class="card mt-6 relative overflow-x-auto" aria-labelledby="movimentacoes-{{ $product->id }}">
        <h2 id="movimentacoes-{{ $product->id }}" class="px-4 pt-5 pb-3 text-sm font-semibold tracking-wide text-slate-500 uppercase sm:px-6">Últimas movimentações</h2>
        @if ($movements->isEmpty())
            <p class="px-4 pt-1 pb-6 text-sm text-slate-600 sm:px-6">Nenhuma movimentação registrada.</p>
        @else
            {{-- Só leitura: corrigir o custo de uma entrada abre um diálogo da página do produto. --}}
            @include('stock._movements_table', ['movements' => $movements, 'product' => $product, 'batch' => null, 'readOnly' => true])
        @endif
    </section>

    @if ($hasMoreMovements)
        <p class="mt-3 text-right text-sm">
            <a href="{{ route('products.show', $product) }}#movimentacoes" class="font-medium text-teal-800 hover:underline">Ver todas as movimentações</a>
        </p>
    @endif
</div>
