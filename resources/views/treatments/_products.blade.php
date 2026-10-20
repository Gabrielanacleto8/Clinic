@php use App\Support\LocalizedDecimal; @endphp
{{-- Produtos aplicados, com lote e unidade física. Espera products.batch.product carregado. --}}
<section class="card mt-6 relative overflow-x-auto">
    <h2 class="px-6 pt-6 text-sm font-semibold tracking-wide text-slate-500 uppercase">Produtos utilizados</h2>
    @if ($treatment->products->isEmpty())
        <p class="px-6 pt-4 pb-6 text-sm text-slate-600">Nenhum produto registrado.</p>
    @else
        <table class="table-stack mt-4 w-full min-w-lg text-left text-sm">
            <thead class="border-y border-slate-200 bg-slate-50 text-xs tracking-wide text-slate-500 uppercase">
                <tr>
                    <th scope="col" class="px-6 py-3 font-medium">Produto</th>
                    <th scope="col" class="px-4 py-3 font-medium">Lote</th>
                    <th scope="col" class="px-4 py-3 font-medium">Validade</th>
                    <th scope="col" class="px-4 py-3 text-right font-medium">Quantidade</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @foreach ($treatment->products as $used)
                    <tr>
                        <td class="px-6 py-3 font-medium">{{ $used->batch->product->name }}</td>
                        <td data-label="Lote" class="px-4 py-3 text-slate-600 tabular-nums">
                            {{ $used->batch->batch_code }}
                            @if ($used->stock_unit_id)
                                <span class="block text-xs">unidade nº {{ $used->stock_unit_id }}</span>
                            @endif
                        </td>
                        <td data-label="Validade" class="px-4 py-3 text-slate-600 tabular-nums">{{ $used->batch->expires_on?->format('d/m/Y') ?? '—' }}</td>
                        <td data-label="Quantidade" class="px-4 py-3 text-right whitespace-nowrap tabular-nums">
                            {{ LocalizedDecimal::quantity($used->quantity) }} {{ $used->batch->product->unit->symbol() }}
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</section>
