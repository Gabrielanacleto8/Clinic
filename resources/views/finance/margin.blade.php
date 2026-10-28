@php
    use App\Support\LocalizedDecimal;

    $filters = ['de' => $start, 'ate' => $end];
    $percent = fn (float $revenue, float $margin) => $revenue > 0 ? number_format($margin / $revenue * 100, 0, ',', '.').'%' : '—';
    $totals = (object) [
        'treatments' => $rows->sum('treatments'),
        'revenue' => $rows->sum('revenue'),
        'cost' => $rows->sum('cost'),
        'uncosted' => $rows->sum('uncosted'),
    ];
    $format = fn (string $date) => Carbon\CarbonImmutable::createFromFormat('!Y-m-d', $date)->format('d/m/Y');
@endphp
<x-layouts.app title="Margem por procedimento" section="finance.index">
    <a href="{{ route('finance.index', $filters) }}" class="text-sm text-slate-600 hover:underline">← Financeiro</a>
    <h1 class="mt-1 text-xl font-semibold tracking-tight">Margem por procedimento</h1>
    <p class="mt-1 text-sm text-slate-600">{{ $format($start) }} a {{ $format($end) }}{{ $professionalId && $professionals->has($professionalId) ? ' · '.$professionals[$professionalId] : '' }}</p>

    <form method="GET" action="{{ route('finance.margin') }}" class="mt-6 grid grid-cols-2 gap-2 sm:grid-cols-12" role="search">
        @include('stock._period_fields', ['span' => 'sm:col-span-3'])
        <div class="col-span-2 sm:col-span-4">
            <label for="profissional" class="sr-only">Profissional</label>
            <select id="profissional" name="profissional" class="input">
                <option value="">Todos os profissionais</option>
                @foreach ($professionals as $id => $name)
                    <option value="{{ $id }}" @selected($professionalId === $id)>{{ $name }}</option>
                @endforeach
            </select>
        </div>
        <button type="submit" class="btn btn-secondary col-span-2 sm:col-span-2">Filtrar</button>
    </form>

    <div class="mt-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
        @foreach ([
            'Atendimentos' => $totals->treatments,
            'Receita' => LocalizedDecimal::money($totals->revenue),
            'Custo de insumos' => LocalizedDecimal::money($totals->cost),
            'Margem' => LocalizedDecimal::money($totals->revenue - $totals->cost).' · '.$percent($totals->revenue, $totals->revenue - $totals->cost),
        ] as $label => $value)
            <div class="card p-4">
                <p class="text-xs text-slate-500">{{ $label }}</p>
                <p class="mt-1 text-lg font-semibold tabular-nums">{{ $value }}</p>
            </div>
        @endforeach
    </div>

    <div class="card mt-6 relative overflow-x-auto">
        <table class="table-stack w-full min-w-2xl text-left text-sm">
            <thead class="border-b border-slate-200 bg-slate-50 text-xs tracking-wide text-slate-500 uppercase">
                <tr>
                    <th scope="col" class="px-4 py-3 font-medium">Procedimento</th>
                    <th scope="col" class="px-4 py-3 text-right font-medium">Atendimentos</th>
                    <th scope="col" class="px-4 py-3 text-right font-medium">Receita</th>
                    <th scope="col" class="px-4 py-3 text-right font-medium">Insumos</th>
                    <th scope="col" class="px-4 py-3 text-right font-medium">Margem</th>
                    <th scope="col" class="px-4 py-3 text-right font-medium">%</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($rows as $row)
                    @php $margin = $row->revenue - $row->cost; @endphp
                    <tr>
                        <td class="px-4 py-3 font-medium">
                            {{ $row->name }}
                            @if ($row->uncosted > 0)
                                <span class="block text-xs font-normal text-amber-800">Há produto sem custo de entrada: o custo está subestimado.</span>
                            @endif
                        </td>
                        <td data-label="Atendimentos" class="px-4 py-3 text-right tabular-nums">{{ $row->treatments }}</td>
                        <td data-label="Receita" class="px-4 py-3 text-right tabular-nums">{{ LocalizedDecimal::money($row->revenue) }}</td>
                        <td data-label="Insumos" class="px-4 py-3 text-right tabular-nums">{{ LocalizedDecimal::money($row->cost) }}</td>
                        <td data-label="Margem" @class(['px-4 py-3 text-right font-medium tabular-nums', 'text-red-700' => $margin < 0])>{{ LocalizedDecimal::money($margin) }}</td>
                        <td data-label="%" class="px-4 py-3 text-right tabular-nums">{{ $percent($row->revenue, $margin) }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-10 text-center text-slate-500">Nenhum atendimento registrado no período.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <p class="mt-4 text-xs text-slate-500">
        Receita: a cobrança do atendimento ou, em sessão de pacote, o valor da sessão no pacote. Insumos: o custo médio dos produtos baixados no atendimento, inclusive a sobra descartada.
        Não entram comissões, impostos nem taxas de cartão. Atendimentos anulados ficam de fora.
    </p>
</x-layouts.app>
