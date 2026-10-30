@php
    use App\Support\LocalizedDecimal;

    // Conteúdo do produto, comum à página e à janela da listagem ($inList).
    // Na janela, as ações de lote ficam de fora: saída, perda, ajuste e baixa
    // abrem diálogos próprios da página do produto.
    $inList ??= false;
    $titleTag = $inList ? 'h3' : 'h1';
    $unit = $product->unit->symbol();
    $canMove = auth()->user()->can('move', $product);
    $canAdjust = auth()->user()->can('adjust', $product);
    $canDiscard = auth()->user()->can('discard', $product);
    $discarding = (float) ($product->discard_quantity ?? 0) > 0;
    $action = 'cursor-pointer font-medium text-slate-800 hover:underline';
@endphp
<div class="flex flex-wrap items-start justify-between gap-3">
    <div>
        <{{ $titleTag }} @class(['font-semibold tracking-tight', 'text-xl' => ! $inList, 'text-lg' => $inList])>{{ $product->name }}</{{ $titleTag }}>
        <p class="mt-1 flex flex-wrap items-center gap-2 text-sm text-slate-600">
            {{ implode(' · ', array_filter([$product->category->label(), $product->brand, $product->code ? 'código '.$product->code : null])) }}
            @unless ($product->is_active)
                <span class="badge bg-slate-200 text-slate-700">Inativo</span>
            @endunless
            @if ($product->isLowOnStock())
                <span class="badge bg-red-100 text-red-800">Estoque baixo</span>
            @endif
        </p>
    </div>
    <div class="flex flex-wrap items-center gap-2">
        @can('update', $product)
            @if ($inList)
                {{-- Abre o diálogo de edição da listagem, por cima desta janela. --}}
                @include('products._edit_button', ['class' => 'btn btn-secondary'])
            @else
                <a href="{{ route('products.edit', $product) }}" class="btn btn-secondary">Editar</a>
            @endif
        @endcan
        @if ($inList)
            @if ($canMove || $canAdjust || $canDiscard)
                <a href="{{ route('products.show', $product) }}" class="btn btn-primary">Movimentar estoque</a>
            @endif
        {{-- Produto inativo não recebe novas entradas. --}}
        @elseif ($canMove && $product->is_active)
            <button type="button" class="btn btn-primary" data-dialog-open="stock-entry">Entrada de estoque</button>
        @endif
    </div>
</div>

<div class="@container mt-6">
    <dl class="grid grid-cols-2 gap-4 @3xl:grid-cols-4">
        @foreach ([
            'Disponível' => [LocalizedDecimal::quantity($product->available_quantity ?? 0).' '.$unit, $discarding ? '+ '.LocalizedDecimal::quantity($product->discard_quantity).' '.$unit.' a descartar' : null],
            'Estoque mínimo' => [(float) $product->minimum_stock > 0 ? LocalizedDecimal::quantity($product->minimum_stock).' '.$unit : '—', null],
            // Custo médio do que está em estoque, pelas entradas de cada lote com saldo. É o custo usado nas baixas.
            'Custo médio em estoque' => [$averageCost !== null ? LocalizedDecimal::unitMoney($averageCost) : '—', $averageCost !== null ? 'por '.$unit.', pelas entradas dos lotes com saldo' : 'nenhuma entrada com custo'],
            'Custo de referência' => [$product->cost !== null ? LocalizedDecimal::unitMoney($product->cost) : '—', 'usado quando a entrada não informa custo'],
        ] as $label => [$value, $note])
            <div class="card px-4 py-3">
                <dt class="text-xs text-slate-500">{{ $label }}</dt>
                <dd class="mt-0.5 text-lg font-semibold tabular-nums">{{ $value }}</dd>
                @if ($note)
                    <dd class="text-xs text-slate-500">{{ $note }}</dd>
                @endif
            </div>
        @endforeach
    </dl>
</div>

@if ($product->isFractioned())
    <p class="mt-3 text-sm text-slate-600">
        Fracionado: {{ LocalizedDecimal::quantity($product->unit_content) }} {{ $unit }} por unidade (frasco, seringa ou ampola)
        · {{ $product->hours_after_opening ? 'usar até '.$product->hours_after_opening.' h depois de aberta' : 'sem prazo depois de aberta além da validade do lote' }}
        · {{ $product->multi_patient_use ? 'sobra pode ir para outro paciente' : 'uso em um só paciente' }}.
        @if ($product->open_units_count > 0)
            <a href="{{ route('stock.units.index', ['produto' => $product->id]) }}" class="font-medium text-slate-800 hover:underline">{{ $product->open_units_count }} {{ $product->open_units_count === 1 ? 'unidade aberta' : 'unidades abertas' }}</a>{{ $discarding ? ', com sobra a descartar' : '' }}.
        @else
            Nenhuma unidade aberta no momento.
        @endif
    </p>
@endif

@if ($product->manufacturer || $product->supplier)
    <p class="mt-3 text-sm text-slate-600">
        @if ($product->manufacturer) Fabricante: {{ $product->manufacturer }} @endif
        @if ($product->manufacturer && $product->supplier) · @endif
        @if ($product->supplier) Fornecedor: {{ $product->supplier }} @endif
    </p>
@endif

<section class="card mt-6 relative overflow-x-auto" aria-labelledby="lotes-{{ $product->id }}">
    <h2 id="lotes-{{ $product->id }}" class="px-4 pt-5 text-sm font-semibold tracking-wide text-slate-500 uppercase sm:px-6">Lotes</h2>
    @if ($batches->isEmpty())
        <p class="px-4 pt-4 pb-6 text-sm text-slate-600 sm:px-6">Nenhum lote ainda. Lance uma entrada de estoque para começar.</p>
    @else
        <table class="table-stack mt-3 w-full min-w-2xl text-left text-sm">
            <thead class="border-y border-slate-200 bg-slate-50 text-xs tracking-wide text-slate-500 uppercase">
                <tr>
                    <th scope="col" class="px-4 py-3 font-medium sm:px-6">Lote</th>
                    <th scope="col" class="px-4 py-3 font-medium">Fabricação</th>
                    <th scope="col" class="px-4 py-3 font-medium">Validade</th>
                    <th scope="col" class="px-4 py-3 text-right font-medium">Saldo</th>
                    @unless ($inList)
                        <th scope="col" class="px-4 py-3 sm:px-6"><span class="sr-only">Ações</span></th>
                    @endunless
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @foreach ($batches as $batch)
                    @php $preset = json_encode(['product_batch_id' => $batch->id]); @endphp
                    <tr @class(['text-slate-400' => (float) $batch->quantity === 0.0])>
                        <td data-label="Lote" class="px-4 py-3 font-medium tabular-nums sm:px-6">
                            <a href="{{ route('stock.batches.show', $batch) }}" class="text-slate-800 hover:underline">{{ $batch->batch_code }}</a>
                        </td>
                        <td data-label="Fabricação" class="px-4 py-3 whitespace-nowrap tabular-nums">{{ $batch->manufactured_on?->format('d/m/Y') ?? '—' }}</td>
                        <td data-label="Validade" class="px-4 py-3 whitespace-nowrap">
                            {{ $batch->expires_on?->format('d/m/Y') ?? 'Sem validade' }}
                            @include('stock._expiry_badge', ['batch' => $batch])
                        </td>
                        <td data-label="Saldo" class="px-4 py-3 text-right whitespace-nowrap tabular-nums">{{ LocalizedDecimal::quantity($batch->quantity) }} {{ $unit }}</td>
                        @unless ($inList)
                            <td class="px-4 py-3 text-right whitespace-nowrap sm:px-6">
                                @if ($batch->isExpired())
                                    @if ($canDiscard && (float) $batch->quantity > 0)
                                        <button type="button" class="cursor-pointer font-medium text-red-700 hover:underline" data-dialog-open="batch-write-off"
                                                data-dialog-action="{{ route('stock.batches.write-off', $batch) }}"
                                                data-dialog-summary="{{ $product->name }} · lote {{ $batch->batch_code }} · {{ LocalizedDecimal::quantity($batch->quantity) }} {{ $unit }}"
                                                data-dialog-values="{{ json_encode(['reason' => 'Vencido em '.$batch->expires_on->format('d/m/Y')], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}">Baixar vencido</button>
                                    @endif
                                @else
                                    @if ($canMove)
                                        <button type="button" class="{{ $action }}" data-dialog-open="stock-exit" data-dialog-values="{{ $preset }}">Saída</button>
                                        <button type="button" class="{{ $action }} ml-3" data-dialog-open="stock-loss" data-dialog-values="{{ $preset }}">Perda</button>
                                    @endif
                                    @if ($canAdjust)
                                        <button type="button" class="{{ $action }} ml-3" data-dialog-open="stock-adjustment"
                                                data-dialog-values="{{ json_encode(['product_batch_id' => $batch->id, 'expected' => LocalizedDecimal::quantityInput($product->isFractioned() ? $batch->sealedQuantity() : $batch->quantity)]) }}">Ajuste</button>
                                    @endif
                                @endif
                            </td>
                        @endunless
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</section>
