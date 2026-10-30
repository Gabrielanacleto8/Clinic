@php
    use App\Enums\StockMovementType;
    use App\Support\ClinicTime;
    use App\Support\LocalizedDecimal;

    // $readOnly: na janela do produto, sem a correção de custo (o diálogo dela é da página).
    $canCorrectCost = ! ($readOnly ?? false) && auth()->user()->can('adjust', App\Models\Product::class);
@endphp
{{-- Linhas do livro de estoque. Espera $movements com user e treatment.professional
     carregados. $product e $batch são sempre informados: na página de um produto
     ou de um lote eles vêm fixos e a coluna some; nulos, vêm de batch.product. --}}
<table class="table-stack w-full min-w-3xl text-left text-sm">
    <thead class="border-y border-slate-200 bg-slate-50 text-xs tracking-wide text-slate-500 uppercase">
        <tr>
            <th scope="col" class="px-4 py-3 font-medium">Data</th>
            <th scope="col" class="px-4 py-3 font-medium">Tipo</th>
            @if (! $product)
                <th scope="col" class="px-4 py-3 font-medium">Produto</th>
            @endif
            @if (! $batch)
                <th scope="col" class="px-4 py-3 font-medium">Lote</th>
            @endif
            <th scope="col" class="px-4 py-3 text-right font-medium">Quantidade</th>
            <th scope="col" class="px-4 py-3 text-right font-medium">Valor</th>
            <th scope="col" class="px-4 py-3 font-medium">Motivo ou origem</th>
            <th scope="col" class="px-4 py-3 font-medium">Usuário</th>
        </tr>
    </thead>
    <tbody class="divide-y divide-slate-100">
        @foreach ($movements as $movement)
            @php
                $rowBatch = $batch ?? $movement->batch;
                $rowProduct = $product ?? $rowBatch->product;
                $positive = (float) $movement->quantity > 0;
                $treatment = $movement->treatment;
                // Entrada: nota fiscal e fornecedor fazem parte da origem.
                $origin = implode(' · ', array_filter([
                    $movement->invoice_number ? 'NF '.$movement->invoice_number : null,
                    $movement->supplier,
                    $movement->reason,
                ]));
            @endphp
            <tr>
                <td data-label="Data" class="px-4 py-3 whitespace-nowrap text-slate-600 tabular-nums">{{ ClinicTime::format($movement->created_at) }}</td>
                <td data-label="Tipo" class="px-4 py-3">
                    <span class="badge {{ $movement->type->badgeClasses() }}">{{ $movement->type->label() }}</span>
                    @if ($movement->stock_unit_id)
                        <a href="{{ route('stock.units.show', $movement->stock_unit_id) }}" class="mt-1 block text-xs text-slate-800 hover:underline">unidade nº {{ $movement->stock_unit_id }}</a>
                    @endif
                </td>
                @if (! $product)
                    <td data-label="Produto" class="px-4 py-3">
                        <a href="{{ route('products.show', $rowProduct) }}" class="font-medium text-slate-800 hover:underline">{{ $rowProduct->name }}</a>
                    </td>
                @endif
                @if (! $batch)
                    <td data-label="Lote" class="px-4 py-3 tabular-nums">
                        <a href="{{ route('stock.batches.show', $rowBatch) }}" class="text-slate-800 hover:underline">{{ $rowBatch->batch_code }}</a>
                    </td>
                @endif
                <td data-label="Quantidade" @class(['px-4 py-3 text-right font-medium whitespace-nowrap tabular-nums', 'text-emerald-700' => $positive, 'text-red-700' => ! $positive])>
                    {{ $positive ? '+' : '−' }}{{ LocalizedDecimal::quantity(abs((float) $movement->quantity)) }} {{ $rowProduct->unit->symbol() }}
                </td>
                {{-- Entrada: valor da compra. Saída, consumo e perda: custo médio do lote na baixa. --}}
                <td data-label="Valor" class="px-4 py-3 text-right whitespace-nowrap text-slate-600 tabular-nums">
                    {{ $movement->value() !== null ? LocalizedDecimal::money($movement->value()) : '—' }}
                    @if ($canCorrectCost && $movement->type === StockMovementType::Entry)
                        <button type="button" class="block w-full cursor-pointer text-right text-xs text-slate-800 hover:underline" data-dialog-open="entry-cost"
                                data-dialog-action="{{ route('stock.movements.cost', $movement) }}"
                                data-dialog-summary="Entrada de {{ ClinicTime::format($movement->created_at) }} · {{ $rowProduct->name }} · lote {{ $rowBatch->batch_code }}"
                                data-dialog-values="{{ json_encode(['unit_cost' => LocalizedDecimal::unitMoneyInput($movement->unit_cost)]) }}">Corrigir custo</button>
                    @endif
                </td>
                <td data-label="Motivo ou origem" class="px-4 py-3 text-slate-600">
                    @if ($treatment)
                        {{-- Rastreabilidade do consumo: o atendimento e quem aplicou. O paciente só aparece no prontuário. --}}
                        @can('view', $treatment)
                            <a href="{{ route('patients.treatments.show', [$treatment->patient_id, $treatment]) }}" class="text-slate-800 hover:underline">Atendimento nº {{ $treatment->id }}</a>
                        @else
                            Atendimento nº {{ $treatment->id }}
                        @endcan
                        {{-- O procedimento é informação clínica: só para quem vê o prontuário. --}}
                        @if ($treatment->relationLoaded('procedure') && $treatment->procedure)
                            @can('view', $treatment)
                                · {{ $treatment->procedure->name }}
                            @endcan
                        @endif
                        @if ($treatment->professional)
                            · {{ $treatment->professional->name }}
                        @endif
                        @if ($movement->reason)
                            <span class="block text-xs">{{ $movement->reason }}</span>
                        @endif
                    @else
                        {{ $origin !== '' ? $origin : '—' }}
                    @endif
                </td>
                <td data-label="Usuário" class="px-4 py-3 text-slate-600">{{ $movement->user?->name ?? '—' }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

@if ($canCorrectCost)
    @once
        @include('stock._entry_cost_dialog')
    @endonce
@endif
