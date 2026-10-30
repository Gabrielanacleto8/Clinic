@php
    use App\Support\ClinicTime;
    use App\Support\LocalizedDecimal;

    $canDiscard = auth()->user()->can('discard', App\Models\Product::class);
    $total = $toDiscard->count() + $inUse->total();
@endphp
<x-stock.page active="unidades" :alerts="$alerts" :subtitle="($product ? $product->name.' · ' : '').$total.' '.($total === 1 ? 'unidade aberta' : 'unidades abertas')">
    @if ($product)
        <p class="mt-4 text-sm">
            <a href="{{ route('stock.units.index') }}" class="text-slate-800 hover:underline">Ver as unidades abertas de todos os produtos</a>
        </p>
    @endif

    <section class="card mt-4 relative overflow-x-auto" aria-labelledby="descartar">
        <div class="px-4 pt-5 pb-3">
            <h2 id="descartar" class="text-sm font-semibold tracking-wide text-slate-500 uppercase">Para descartar</h2>
            <p class="mt-1 text-sm text-slate-600">
                Unidades que ainda têm conteúdo mas não podem ir para paciente: prazo depois de aberta vencido, lote vencido, produto inativo ou uso em um só paciente já feito.
                O descarte registra a sobra como perda.
            </p>
        </div>
        @if ($toDiscard->isEmpty())
            <p class="px-4 pb-6 text-sm text-slate-600">Nenhuma unidade aguardando descarte.</p>
        @else
            <table class="table-stack w-full min-w-4xl text-left text-sm">
                <thead class="border-y border-slate-200 bg-slate-50 text-xs tracking-wide text-slate-500 uppercase">
                    <tr>
                        <th scope="col" class="px-4 py-3 font-medium">Unidade</th>
                        <th scope="col" class="px-4 py-3 font-medium">Produto e lote</th>
                        <th scope="col" class="px-4 py-3 font-medium">Aberta em</th>
                        <th scope="col" class="px-4 py-3 text-right font-medium">Resta</th>
                        <th scope="col" class="px-4 py-3 font-medium">Motivo</th>
                        @if ($canDiscard)
                            <th scope="col" class="px-4 py-3"><span class="sr-only">Ações</span></th>
                        @endif
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($toDiscard as $unit)
                        @php $unitProduct = $unit->batch->product; $reason = $unit->discardReason(); @endphp
                        <tr>
                            <td data-label="Unidade" class="px-4 py-3 font-medium">
                                <a href="{{ route('stock.units.show', $unit) }}" class="text-slate-800 hover:underline">{{ $unit->label() }}</a>
                            </td>
                            <td data-label="Produto e lote" class="px-4 py-3">
                                <a href="{{ route('products.show', $unitProduct) }}" class="hover:underline">{{ $unitProduct->name }}</a>
                                <span class="block text-xs text-slate-500">lote {{ $unit->batch->batch_code }}</span>
                            </td>
                            <td data-label="Aberta em" class="px-4 py-3 whitespace-nowrap text-slate-600 tabular-nums">{{ ClinicTime::format($unit->opened_at) }}</td>
                            <td data-label="Resta" class="px-4 py-3 text-right whitespace-nowrap tabular-nums">{{ LocalizedDecimal::quantity($unit->quantity) }} {{ $unitProduct->unit->symbol() }}</td>
                            <td data-label="Motivo" class="px-4 py-3">
                                @if ($canDiscard)
                                    <label for="discard-reason-{{ $unit->id }}" class="sr-only">Motivo do descarte da {{ $unit->label() }}</label>
                                    <input id="discard-reason-{{ $unit->id }}" form="discard-{{ $unit->id }}" name="reason" type="text" maxlength="255" autocomplete="off"
                                           value="{{ old('_unit') === (string) $unit->id ? old('reason', $reason) : $reason }}" @class(['input', 'input-error' => old('_unit') === (string) $unit->id && $errors->has('reason')])>
                                    @if (old('_unit') === (string) $unit->id)
                                        @error('reason')
                                            <p class="mt-1 text-sm text-red-700">{{ $message }}</p>
                                        @enderror
                                    @endif
                                @else
                                    <span class="text-red-800">{{ $reason }}</span>
                                @endif
                            </td>
                            @if ($canDiscard)
                                <td class="px-4 py-3 text-right whitespace-nowrap">
                                    <form id="discard-{{ $unit->id }}" method="POST" action="{{ route('stock.units.discard', $unit) }}" data-confirm="Descartar o que resta na {{ mb_strtolower($unit->label()) }} ({{ LocalizedDecimal::quantity($unit->quantity) }} {{ $unitProduct->unit->symbol() }})?">
                                        @csrf
                                        <input type="hidden" name="_unit" value="{{ $unit->id }}">
                                        <button type="submit" class="btn btn-danger">Descartar</button>
                                    </form>
                                </td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </section>

    <section class="card mt-6 relative overflow-x-auto" aria-labelledby="em-uso">
        <div class="px-4 pt-5 pb-3">
            <h2 id="em-uso" class="text-sm font-semibold tracking-wide text-slate-500 uppercase">Em uso</h2>
            <p class="mt-1 text-sm text-slate-600">Da aberta há mais tempo à mais recente. O registro de atendimento sugere a mais antiga de cada lote.</p>
        </div>
        @if ($inUse->isEmpty())
            <p class="px-4 pb-6 text-sm text-slate-600">Nenhuma unidade aberta em uso.</p>
        @else
            <table class="table-stack w-full min-w-3xl text-left text-sm">
                <thead class="border-y border-slate-200 bg-slate-50 text-xs tracking-wide text-slate-500 uppercase">
                    <tr>
                        <th scope="col" class="px-4 py-3 font-medium">Unidade</th>
                        <th scope="col" class="px-4 py-3 font-medium">Produto e lote</th>
                        <th scope="col" class="px-4 py-3 font-medium">Aberta em</th>
                        <th scope="col" class="px-4 py-3 font-medium">Usar até</th>
                        <th scope="col" class="px-4 py-3 text-right font-medium">Resta</th>
                        <th scope="col" class="px-4 py-3 font-medium">Sobra</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($inUse as $unit)
                        @php $unitProduct = $unit->batch->product; $until = $unit->usableUntil(); @endphp
                        <tr>
                            <td data-label="Unidade" class="px-4 py-3 font-medium">
                                <a href="{{ route('stock.units.show', $unit) }}" class="text-slate-800 hover:underline">{{ $unit->label() }}</a>
                            </td>
                            <td data-label="Produto e lote" class="px-4 py-3">
                                <a href="{{ route('products.show', $unitProduct) }}" class="hover:underline">{{ $unitProduct->name }}</a>
                                <span class="block text-xs text-slate-500">lote {{ $unit->batch->batch_code }}</span>
                            </td>
                            <td data-label="Aberta em" class="px-4 py-3 whitespace-nowrap text-slate-600 tabular-nums">{{ ClinicTime::format($unit->opened_at) }}</td>
                            <td data-label="Usar até" class="px-4 py-3 whitespace-nowrap tabular-nums">
                                @if ($until)
                                    <span @class(['text-amber-700' => $until->lessThan(now()->addHours(12))])>{{ ClinicTime::format($until) }}</span>
                                @else
                                    <span class="text-slate-600">Validade do lote{{ $unit->batch->expires_on ? ' ('.$unit->batch->expires_on->format('d/m/Y').')' : '' }}</span>
                                @endif
                            </td>
                            <td data-label="Resta" class="px-4 py-3 text-right whitespace-nowrap tabular-nums">{{ LocalizedDecimal::quantity($unit->quantity) }} {{ $unitProduct->unit->symbol() }}</td>
                            <td data-label="Sobra" class="px-4 py-3 text-slate-600">{{ $unitProduct->multi_patient_use ? 'Pode ir para outro paciente' : 'Uso em um só paciente' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </section>

    <div class="mt-4">
        {{ $inUse->links() }}
    </div>
</x-stock.page>
