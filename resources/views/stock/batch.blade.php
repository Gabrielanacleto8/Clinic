@php
    use App\Enums\StockMovementType;
    use App\Support\ClinicTime;
    use App\Support\LocalizedDecimal;

    $unit = $product->unit->symbol();
    $sum = fn (StockMovementType $type) => LocalizedDecimal::quantity(abs((float) ($totals[$type->value] ?? 0))).' '.$unit;
    $fractioned = $product->isFractioned();
    $sealed = $fractioned ? $batch->sealedQuantity() : $batch->quantity;
    $sealedUnits = $fractioned ? (int) floor((float) $sealed / (float) $product->unit_content) : 0;
    $batchOptions = [$batch->id => $batch->batch_code.($fractioned
        ? ' · fechado '.LocalizedDecimal::quantity($sealed).' '.$unit
        : ' · saldo '.LocalizedDecimal::quantity($batch->quantity).' '.$unit)];
    $preset = json_encode(['product_batch_id' => $batch->id]);
    // O ajuste leva o saldo fechado que a tela mostra: se ele mudar até o envio, o ajuste é recusado.
    $adjustPreset = json_encode(['product_batch_id' => $batch->id, 'expected' => LocalizedDecimal::quantityInput($sealed)]);
    $expired = $batch->isExpired();
    $canDiscard = auth()->user()->can('discard', $product);
@endphp
<x-layouts.app :title="'Lote '.$batch->batch_code">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <a href="{{ route('stock.batches.index') }}" class="text-sm text-slate-600 hover:underline">← Lotes e validade</a>
            <h1 class="mt-1 text-xl font-semibold tracking-tight">Lote {{ $batch->batch_code }}</h1>
            <p class="mt-1 text-sm text-slate-600">
                <a href="{{ route('products.show', $product) }}" class="text-slate-800 hover:underline">{{ $product->name }}</a>
                @unless ($product->is_active)
                    <span class="badge ml-1 bg-slate-200 text-slate-700">Inativo</span>
                @endunless
            </p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            {{-- Lote vencido não circula: só sai inteiro como perda. Saída e ajuste nem aparecem. --}}
            @if ($expired && (float) $batch->quantity > 0 && $canDiscard)
                <button type="button" class="btn btn-danger" data-dialog-open="batch-write-off"
                        data-dialog-action="{{ route('stock.batches.write-off', $batch) }}"
                        data-dialog-summary="{{ $product->name }} · lote {{ $batch->batch_code }} · {{ LocalizedDecimal::quantity($batch->quantity) }} {{ $unit }}"
                        data-dialog-values="{{ json_encode(['reason' => 'Vencido em '.$batch->expires_on->format('d/m/Y')], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}">Baixar vencido como perda</button>
            @endif
            @can('move', $product)
                @if ($fractioned && $sealedUnits > 0 && ! $expired && $product->is_active)
                    <form method="POST" action="{{ route('stock.units.store', $batch) }}" data-confirm="Abrir uma unidade fechada deste lote?">
                        @csrf
                        <button type="submit" class="btn btn-primary">Abrir unidade</button>
                    </form>
                @endif
                @unless ($expired)
                    <button type="button" class="btn btn-secondary" data-dialog-open="stock-exit" data-dialog-values="{{ $preset }}">Saída</button>
                    <button type="button" class="btn btn-secondary" data-dialog-open="stock-loss" data-dialog-values="{{ $preset }}">Perda</button>
                @endunless
            @endcan
            @can('adjust', $product)
                @unless ($expired)
                    <button type="button" class="btn btn-secondary" data-dialog-open="stock-adjustment" data-dialog-values="{{ $adjustPreset }}">Ajuste</button>
                @endunless
                <button type="button" class="btn btn-secondary" data-dialog-open="batch-dates">Corrigir lote</button>
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

    @error('unit')
        <p class="mt-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900" role="alert">{{ $message }}</p>
    @enderror

    @if ($lastCount)
        <p class="mt-3 text-sm text-slate-600">
            Última conferência de inventário em {{ ClinicTime::format($lastCount->created_at) }}{{ $lastCount->user ? ' por '.$lastCount->user->name : '' }}
            {{ in_array($batch->id, $lastCount->metadata['adjusted_batch_ids'] ?? [], true) ? '(saldo ajustado)' : '(saldo conferia)' }}.
        </p>
    @else
        <p class="mt-3 text-sm text-slate-600">Este lote ainda não passou por conferência de inventário.</p>
    @endif

    <p class="mt-3 text-sm text-slate-600">
        Entradas: {{ $sum(StockMovementType::Entry) }} · Saídas: {{ $sum(StockMovementType::Exit) }} · Perdas: {{ $sum(StockMovementType::Loss) }}
        @if ((float) ($totals[StockMovementType::Reversal->value] ?? 0) > 0)
            · Devolvido por atendimentos anulados: {{ $sum(StockMovementType::Reversal) }}
        @endif
        @if ($lostValue > 0)
            ({{ LocalizedDecimal::money($lostValue) }} desperdiçados)
        @endif
    </p>

    @if ($fractioned)
        <section class="card mt-6 relative overflow-x-auto" aria-labelledby="unidades">
            <div class="px-4 pt-5 pb-3">
                <h2 id="unidades" class="text-sm font-semibold tracking-wide text-slate-500 uppercase">Unidades (frascos, seringas, ampolas)</h2>
                <p class="mt-1 text-sm text-slate-600">
                    {{ $sealedUnits }} {{ $sealedUnits === 1 ? 'unidade fechada' : 'unidades fechadas' }} de {{ LocalizedDecimal::quantity($product->unit_content) }} {{ $unit }}
                    · {{ $units->count() }} {{ $units->count() === 1 ? 'aberta com saldo' : 'abertas com saldo' }}.
                    {{ $product->multi_patient_use ? 'A sobra pode ir para outro paciente dentro do prazo.' : 'Uso em um só paciente: a sobra é descartada ao fim do atendimento.' }}
                </p>
            </div>
            @if ($units->isNotEmpty())
                <table class="table-stack w-full min-w-2xl text-left text-sm">
                    <thead class="border-y border-slate-200 bg-slate-50 text-xs tracking-wide text-slate-500 uppercase">
                        <tr>
                            <th scope="col" class="px-4 py-3 font-medium">Unidade</th>
                            <th scope="col" class="px-4 py-3 font-medium">Aberta em</th>
                            <th scope="col" class="px-4 py-3 font-medium">Usar até</th>
                            <th scope="col" class="px-4 py-3 text-right font-medium">Resta</th>
                            <th scope="col" class="px-4 py-3 font-medium">Situação</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($units as $openUnit)
                            <tr>
                                <td data-label="Unidade" class="px-4 py-3 font-medium">
                                    <a href="{{ route('stock.units.show', $openUnit) }}" class="text-slate-800 hover:underline">{{ $openUnit->label() }}</a>
                                </td>
                                <td data-label="Aberta em" class="px-4 py-3 whitespace-nowrap text-slate-600 tabular-nums">{{ ClinicTime::format($openUnit->opened_at) }}</td>
                                <td data-label="Usar até" class="px-4 py-3 whitespace-nowrap text-slate-600 tabular-nums">{{ $openUnit->usableUntil() ? ClinicTime::format($openUnit->usableUntil()) : 'Validade do lote' }}</td>
                                <td data-label="Resta" class="px-4 py-3 text-right whitespace-nowrap tabular-nums">{{ LocalizedDecimal::quantity($openUnit->quantity) }} {{ $unit }}</td>
                                <td data-label="Situação" class="px-4 py-3">
                                    @if ($openUnit->isUsable())
                                        <span class="badge bg-emerald-100 text-emerald-800">Em uso</span>
                                    @else
                                        <span class="badge bg-red-100 text-red-800">Descartar</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </section>
    @endif

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

    @if ($canDiscard && $expired)
        @include('stock._write_off_dialog')
    @endif

    @can('adjust', $product)
        <x-dialog id="batch-dates" title="Corrigir lote">
            <form method="POST" action="{{ route('stock.batches.update', $batch) }}" class="space-y-4" novalidate>
                @csrf
                @method('PATCH')
                <input type="hidden" name="_dialog" value="batch-dates">

                <p class="text-sm text-slate-600">
                    Use para corrigir código, fabricação ou validade digitados errado na entrada. A correção fica registrada na auditoria, com os valores antigos e os novos.
                </p>
                <x-form.input scope="batch-dates" id="batch-code" name="batch_code" label="Código do lote" required maxlength="60" autocomplete="off"
                              :value="$batch->batch_code" hint="É o código da rastreabilidade: confira com o rótulo do produto." />
                <x-form.input scope="batch-dates" id="batch-manufactured" name="manufactured_on" label="Fabricação" type="date"
                              :value="$batch->manufactured_on?->format('Y-m-d')" :max="ClinicTime::today()" />
                <x-form.input scope="batch-dates" id="batch-expires" name="expires_on" label="Validade" type="date"
                              :value="$batch->expires_on?->format('Y-m-d')" hint="Se a data correta já passou, o lote passa a constar como vencido." />

                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" class="btn btn-secondary" data-dialog-close>Cancelar</button>
                    <button type="submit" class="btn btn-primary">Salvar datas</button>
                </div>
            </form>
        </x-dialog>
    @endcan
</x-layouts.app>
