@php
    use App\Enums\StockMovementType;
    use App\Support\ClinicTime;
    use App\Support\LocalizedDecimal;

    $unit = $product->unit->symbol();
    $batchOptions = $batches->mapWithKeys(fn ($batch) => [
        $batch->id => $batch->batch_code.' · saldo '.LocalizedDecimal::quantity($batch->quantity).' '.$unit,
    ])->all();
    $movementUrl = route('products.movements.store', $product);
    $canMove = auth()->user()->can('move', $product);
@endphp
<x-layouts.app :title="$product->name">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <a href="{{ route('products.index') }}" class="text-sm text-slate-600 hover:underline">← Estoque</a>
            <h1 class="mt-1 text-xl font-semibold tracking-tight">{{ $product->name }}</h1>
            <p class="mt-1 flex flex-wrap items-center gap-2 text-sm text-slate-600">
                {{ $product->category->label() }}@if ($product->brand) · {{ $product->brand }}@endif
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
                <a href="{{ route('products.edit', $product) }}" class="btn btn-secondary">Editar</a>
            @endcan
            @if ($canMove)
                <button type="button" class="btn btn-primary" data-dialog-open="stock-entry">Entrada de estoque</button>
            @endif
        </div>
    </div>

    <dl class="mt-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
        @foreach ([
            'Disponível' => LocalizedDecimal::quantity($product->available_quantity ?? 0).' '.$unit,
            'Estoque mínimo' => (float) $product->minimum_stock > 0 ? LocalizedDecimal::quantity($product->minimum_stock).' '.$unit : '—',
            'Custo unitário' => $product->cost !== null ? LocalizedDecimal::money($product->cost) : '—',
            'Preço unitário' => $product->price !== null ? LocalizedDecimal::money($product->price) : '—',
        ] as $label => $value)
            <div class="card px-4 py-3">
                <dt class="text-xs text-slate-500">{{ $label }}</dt>
                <dd class="mt-0.5 text-lg font-semibold tabular-nums">{{ $value }}</dd>
            </div>
        @endforeach
    </dl>

    @if ($product->manufacturer || $product->supplier)
        <p class="mt-3 text-sm text-slate-600">
            @if ($product->manufacturer) Fabricante: {{ $product->manufacturer }} @endif
            @if ($product->manufacturer && $product->supplier) · @endif
            @if ($product->supplier) Fornecedor: {{ $product->supplier }} @endif
        </p>
    @endif

    <section class="card mt-6 overflow-x-auto" aria-labelledby="lotes">
        <h2 id="lotes" class="px-4 pt-5 text-sm font-semibold tracking-wide text-slate-500 uppercase sm:px-6">Lotes</h2>
        @if ($batches->isEmpty())
            <p class="px-4 pt-4 pb-6 text-sm text-slate-600 sm:px-6">Nenhum lote ainda. Lance uma entrada de estoque para começar.</p>
        @else
            <table class="mt-3 w-full min-w-xl text-left text-sm">
                <thead class="border-y border-slate-200 bg-slate-50 text-xs tracking-wide text-slate-500 uppercase">
                    <tr>
                        <th scope="col" class="px-4 py-3 font-medium sm:px-6">Lote</th>
                        <th scope="col" class="px-4 py-3 font-medium">Validade</th>
                        <th scope="col" class="px-4 py-3 text-right font-medium">Saldo</th>
                        <th scope="col" class="px-4 py-3 sm:px-6"><span class="sr-only">Ações</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($batches as $batch)
                        <tr @class(['text-slate-400' => (float) $batch->quantity === 0.0])>
                            <td class="px-4 py-3 font-medium tabular-nums sm:px-6">{{ $batch->batch_code }}</td>
                            <td class="px-4 py-3 whitespace-nowrap">
                                {{ $batch->expires_on?->format('d/m/Y') ?? 'Sem validade' }}
                                @if ((float) $batch->quantity > 0)
                                    @if ($batch->isExpired())
                                        <span class="badge ml-1 bg-red-100 text-red-800">Vencido</span>
                                    @elseif ($batch->expiresSoon())
                                        <span class="badge ml-1 bg-amber-100 text-amber-800">
                                            {{ $batch->daysToExpire() === 0 ? 'Vence hoje' : 'Vence em '.$batch->daysToExpire().' '.($batch->daysToExpire() === 1 ? 'dia' : 'dias') }}
                                        </span>
                                    @endif
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right whitespace-nowrap tabular-nums">{{ LocalizedDecimal::quantity($batch->quantity) }} {{ $unit }}</td>
                            <td class="px-4 py-3 text-right whitespace-nowrap sm:px-6">
                                @if ($canMove)
                                    <button type="button" class="cursor-pointer font-medium text-teal-800 hover:underline"
                                            data-dialog-open="stock-exit" data-dialog-values='@json(['product_batch_id' => $batch->id])'>Saída</button>
                                    <button type="button" class="ml-3 cursor-pointer font-medium text-teal-800 hover:underline"
                                            data-dialog-open="stock-adjustment" data-dialog-values='@json(['product_batch_id' => $batch->id])'>Ajuste</button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </section>

    <section class="card mt-6 overflow-x-auto" aria-labelledby="movimentacoes">
        <h2 id="movimentacoes" class="px-4 pt-5 text-sm font-semibold tracking-wide text-slate-500 uppercase sm:px-6">Histórico de movimentações</h2>
        @if ($movements->isEmpty())
            <p class="px-4 pt-4 pb-6 text-sm text-slate-600 sm:px-6">Nenhuma movimentação registrada.</p>
        @else
            <table class="mt-3 w-full min-w-2xl text-left text-sm">
                <thead class="border-y border-slate-200 bg-slate-50 text-xs tracking-wide text-slate-500 uppercase">
                    <tr>
                        <th scope="col" class="px-4 py-3 font-medium sm:px-6">Data</th>
                        <th scope="col" class="px-4 py-3 font-medium">Tipo</th>
                        <th scope="col" class="px-4 py-3 font-medium">Lote</th>
                        <th scope="col" class="px-4 py-3 text-right font-medium">Quantidade</th>
                        <th scope="col" class="px-4 py-3 font-medium">Motivo</th>
                        <th scope="col" class="px-4 py-3 font-medium sm:px-6">Por</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($movements as $movement)
                        @php $positive = (float) $movement->quantity > 0; @endphp
                        <tr>
                            <td class="px-4 py-3 whitespace-nowrap text-slate-600 tabular-nums sm:px-6">{{ ClinicTime::format($movement->created_at) }}</td>
                            <td class="px-4 py-3">{{ $movement->type->label() }}</td>
                            <td class="px-4 py-3 tabular-nums">{{ $movement->batch->batch_code }}</td>
                            <td @class(['px-4 py-3 text-right font-medium whitespace-nowrap tabular-nums', 'text-emerald-700' => $positive, 'text-red-700' => ! $positive])>
                                {{ $positive ? '+' : '−' }}{{ LocalizedDecimal::quantity(abs((float) $movement->quantity)) }} {{ $unit }}
                            </td>
                            <td class="px-4 py-3 text-slate-600">
                                @if ($movement->treatment)
                                    @can('view', $movement->treatment)
                                        <a href="{{ route('patients.treatments.show', [$movement->treatment->patient_id, $movement->treatment]) }}" class="text-teal-800 hover:underline">Atendimento nº {{ $movement->treatment->id }}</a>
                                    @else
                                        Atendimento nº {{ $movement->treatment->id }}
                                    @endcan
                                @else
                                    {{ $movement->reason ?: '—' }}
                                @endif
                            </td>
                            <td class="px-4 py-3 text-slate-600 sm:px-6">{{ $movement->user?->name ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </section>

    <div class="mt-4">
        {{ $movements->links() }}
    </div>

    @if ($canMove)
        <x-dialog id="stock-entry" title="Entrada de estoque">
            <form method="POST" action="{{ $movementUrl }}" class="space-y-4" novalidate>
                @csrf
                <input type="hidden" name="_dialog" value="stock-entry">
                <input type="hidden" name="type" value="{{ StockMovementType::Entry->value }}">

                <x-form.input scope="stock-entry" id="entry-batch" name="batch_code" label="Lote" required maxlength="60" autocomplete="off" list="lotes-existentes"
                              hint="Um código novo cria o lote. Um código existente soma ao saldo dele." />
                <datalist id="lotes-existentes">
                    @foreach ($batches as $batch)
                        <option value="{{ $batch->batch_code }}"></option>
                    @endforeach
                </datalist>
                <x-form.input scope="stock-entry" id="entry-expires" name="expires_on" label="Validade" type="date" :min="ClinicTime::today()" />
                <x-form.input scope="stock-entry" id="entry-quantity" name="quantity" :label="'Quantidade ('.$unit.')'" required inputmode="decimal" autocomplete="off" />
                <x-form.input scope="stock-entry" id="entry-reason" name="reason" label="Observação" maxlength="255" autocomplete="off" placeholder="Ex.: nota fiscal 1234" />

                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" class="btn btn-secondary" data-dialog-close>Cancelar</button>
                    <button type="submit" class="btn btn-primary">Registrar entrada</button>
                </div>
            </form>
        </x-dialog>

        <x-dialog id="stock-exit" title="Saída de estoque">
            <form method="POST" action="{{ $movementUrl }}" class="space-y-4" novalidate>
                @csrf
                <input type="hidden" name="_dialog" value="stock-exit">
                <input type="hidden" name="type" value="{{ StockMovementType::Exit->value }}">

                <x-form.select scope="stock-exit" id="exit-batch" name="product_batch_id" label="Lote" :options="$batchOptions" required placeholder="Selecione" />
                <x-form.input scope="stock-exit" id="exit-quantity" name="quantity" :label="'Quantidade ('.$unit.')'" required inputmode="decimal" autocomplete="off" />
                <x-form.input scope="stock-exit" id="exit-reason" name="reason" label="Motivo" maxlength="255" autocomplete="off" placeholder="Ex.: perda, quebra, descarte por vencimento"
                              hint="O uso em atendimentos é baixado automaticamente ao finalizar o atendimento." />

                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" class="btn btn-secondary" data-dialog-close>Cancelar</button>
                    <button type="submit" class="btn btn-primary">Registrar saída</button>
                </div>
            </form>
        </x-dialog>

        <x-dialog id="stock-adjustment" title="Ajuste de estoque">
            <form method="POST" action="{{ $movementUrl }}" class="space-y-4" novalidate>
                @csrf
                <input type="hidden" name="_dialog" value="stock-adjustment">
                <input type="hidden" name="type" value="{{ StockMovementType::Adjustment->value }}">

                <x-form.select scope="stock-adjustment" id="adjustment-batch" name="product_batch_id" label="Lote" :options="$batchOptions" required placeholder="Selecione" />
                <x-form.input scope="stock-adjustment" id="adjustment-quantity" name="quantity" :label="'Quantidade contada ('.$unit.')'" required inputmode="decimal" autocomplete="off"
                              hint="Informe o saldo real do lote. O sistema calcula e registra a diferença." />
                <x-form.input scope="stock-adjustment" id="adjustment-reason" name="reason" label="Motivo" required maxlength="255" autocomplete="off" placeholder="Ex.: conferência de inventário" />

                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" class="btn btn-secondary" data-dialog-close>Cancelar</button>
                    <button type="submit" class="btn btn-primary">Registrar ajuste</button>
                </div>
            </form>
        </x-dialog>
    @endif
</x-layouts.app>
