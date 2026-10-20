@php
    use App\Enums\StockMovementType;
    use App\Support\ClinicTime;
    use App\Support\LocalizedDecimal;

    $symbol = $product->unit->symbol();
    $total = fn (StockMovementType $type) => abs((float) ($totals->get($type->value)?->total ?? 0));
    $lostValue = abs((float) ($totals->get(StockMovementType::Loss->value)?->value ?? 0));
    $usableUntil = $unit->usableUntil();
    $canDiscard = (float) $unit->quantity > 0 && auth()->user()->can('move', $product);
@endphp
<x-layouts.app :title="$unit->label()">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <a href="{{ route('stock.batches.show', $batch) }}" class="text-sm text-slate-600 hover:underline">← Lote {{ $batch->batch_code }}</a>
            <h1 class="mt-1 text-xl font-semibold tracking-tight">{{ $unit->label() }}</h1>
            <p class="mt-1 flex flex-wrap items-center gap-2 text-sm text-slate-600">
                <a href="{{ route('products.show', $product) }}" class="text-teal-800 hover:underline">{{ $product->name }}</a>
                · lote {{ $batch->batch_code }}
                @if ((float) $unit->quantity <= 0)
                    <span class="badge bg-slate-200 text-slate-700">Esgotada</span>
                @elseif ($unit->isUsable())
                    <span class="badge bg-emerald-100 text-emerald-800">Em uso</span>
                @else
                    <span class="badge bg-red-100 text-red-800">Descartar: {{ $unit->unusableReason() }}</span>
                @endif
            </p>
        </div>
        @if ($canDiscard)
            <button type="button" class="btn btn-secondary" data-dialog-open="unit-discard">Descartar sobra</button>
        @endif
    </div>

    <dl class="mt-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
        @foreach ([
            'Saldo' => LocalizedDecimal::quantity($unit->quantity).' '.$symbol,
            'Conteúdo inicial' => LocalizedDecimal::quantity($unit->initial_quantity).' '.$symbol,
            'Usado em atendimentos' => LocalizedDecimal::quantity($total(StockMovementType::Consumption)).' '.$symbol,
            'Descartado' => LocalizedDecimal::quantity($total(StockMovementType::Loss)).' '.$symbol.($lostValue > 0 ? ' · '.LocalizedDecimal::money($lostValue) : ''),
        ] as $label => $value)
            <div class="card px-4 py-3">
                <dt class="text-xs text-slate-500">{{ $label }}</dt>
                <dd class="mt-0.5 text-lg font-semibold tabular-nums">{{ $value }}</dd>
            </div>
        @endforeach
    </dl>

    <p class="mt-3 text-sm text-slate-600">
        Aberta em {{ ClinicTime::format($unit->opened_at) }}{{ $unit->openedBy ? ' por '.$unit->openedBy->name : '' }}
        · usar até {{ $usableUntil ? ClinicTime::format($usableUntil) : 'a validade do lote' }}
        · validade do lote {{ $batch->expires_on?->format('d/m/Y') ?? 'não informada' }}
        · {{ $product->multi_patient_use ? 'sobra pode ir para outro paciente' : 'uso em um só paciente' }}
    </p>

    <section class="card mt-6 relative overflow-x-auto" aria-labelledby="historico">
        <h2 id="historico" class="px-4 pt-5 pb-3 text-sm font-semibold tracking-wide text-slate-500 uppercase">Histórico da unidade</h2>
        @if ($movements->isEmpty())
            <p class="px-4 pb-6 text-sm text-slate-600">Nenhum uso registrado.</p>
        @else
            @include('stock._movements_table', ['movements' => $movements, 'product' => $product, 'batch' => $batch])
        @endif
    </section>

    <div class="mt-4">
        {{ $movements->links() }}
    </div>

    @if ($canDiscard)
        <x-dialog id="unit-discard" title="Descartar sobra">
            <form method="POST" action="{{ route('stock.units.discard', $unit) }}" class="space-y-4" novalidate>
                @csrf
                <input type="hidden" name="_dialog" value="unit-discard">
                <p class="text-sm text-slate-600">
                    Registra como perda tudo o que resta: {{ LocalizedDecimal::quantity($unit->quantity) }} {{ $symbol }}.
                </p>
                <x-form.input scope="unit-discard" id="discard-reason" name="reason" label="Motivo" required maxlength="255" autocomplete="off"
                              placeholder="Ex.: prazo depois de aberto vencido, contaminação" />

                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" class="btn btn-secondary" data-dialog-close>Cancelar</button>
                    <button type="submit" class="btn btn-primary">Registrar descarte</button>
                </div>
            </form>
        </x-dialog>
    @endif
</x-layouts.app>
