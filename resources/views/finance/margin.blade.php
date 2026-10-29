@php
    use App\Support\LocalizedDecimal;

    $filters = ['de' => $start, 'ate' => $end];
    $percent = fn (float $base, float $value) => $base > 0 ? number_format($value / $base * 100, 0, ',', '.').'%' : '—';
    $net = fn (object $row) => $row->revenue - $row->refunded - $row->cost - $row->commission;
    $totals = (object) [
        'treatments' => $rows->sum('treatments'),
        'revenue' => $rows->sum('revenue'),
        'discount' => $rows->sum('discount'),
        'refunded' => $rows->sum('refunded'),
        'cost' => $rows->sum('cost'),
        'commission' => $rows->sum('commission'),
    ];
    $totalMargin = $totals->revenue - $totals->refunded - $totals->cost - $totals->commission;
    $format = fn (string $date) => Carbon\CarbonImmutable::createFromFormat('!Y-m-d', $date)->format('d/m/Y');
    $byProfessional = $group === 'profissional';
@endphp
<x-finance.page active="receitas" title="Receitas e margem"
                :subtitle="$format($start).' a '.$format($end).($professionalId && $professionals->has($professionalId) ? ' · '.$professionals[$professionalId] : '')">
    <form method="GET" action="{{ route('finance.margin') }}" class="mt-4 grid grid-cols-2 gap-2 sm:grid-cols-12" role="search">
        @include('stock._period_fields', ['span' => 'sm:col-span-3'])
        <div class="col-span-2 sm:col-span-2">
            <label for="agrupar" class="sr-only">Agrupar</label>
            <select id="agrupar" name="agrupar" class="input">
                @foreach ($groups as $value => $label)
                    <option value="{{ $value }}" @selected($group === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-span-2 sm:col-span-2">
            <label for="profissional" class="sr-only">Profissional</label>
            <select id="profissional" name="profissional" class="input">
                <option value="">Todos os profissionais</option>
                @foreach ($professionals as $id => $name)
                    <option value="{{ $id }}" @selected($professionalId === $id)>{{ $name }}</option>
                @endforeach
            </select>
        </div>
        <button type="submit" class="btn btn-secondary col-span-2 sm:col-span-2">Filtrar</button>
        @foreach (['de', 'ate', 'agrupar', 'profissional'] as $field)
            @error($field)
                <p class="col-span-2 text-sm text-red-700 sm:col-span-12">{{ $message }}</p>
            @enderror
        @endforeach
    </form>

    <div class="mt-6 grid grid-cols-2 gap-4 lg:grid-cols-6">
        @foreach ([
            'Atendimentos' => $totals->treatments,
            'Receita' => LocalizedDecimal::money($totals->revenue),
            'Descontos concedidos' => LocalizedDecimal::money($totals->discount),
            'Estornos' => LocalizedDecimal::money($totals->refunded),
            'Insumos e comissões' => LocalizedDecimal::money($totals->cost + $totals->commission),
            'Margem' => LocalizedDecimal::money($totalMargin).' · '.$percent($totals->revenue, $totalMargin),
        ] as $label => $value)
            <div class="card p-4">
                <p class="text-xs text-slate-500">{{ $label }}</p>
                <p class="mt-1 text-lg font-semibold tabular-nums">{{ $value }}</p>
            </div>
        @endforeach
    </div>

    <div class="card mt-6 relative overflow-x-auto">
        <table class="table-stack w-full min-w-3xl text-left text-sm">
            <thead class="border-b border-slate-200 bg-slate-50 text-xs tracking-wide text-slate-500 uppercase">
                <tr>
                    <th scope="col" class="px-4 py-3 font-medium">{{ $byProfessional ? 'Profissional' : 'Procedimento' }}</th>
                    <th scope="col" class="px-4 py-3 text-right font-medium">Atendimentos</th>
                    <th scope="col" class="px-4 py-3 text-right font-medium">Receita</th>
                    <th scope="col" class="px-4 py-3 text-right font-medium">Descontos</th>
                    <th scope="col" class="px-4 py-3 text-right font-medium">Estornos</th>
                    <th scope="col" class="px-4 py-3 text-right font-medium">Insumos</th>
                    <th scope="col" class="px-4 py-3 text-right font-medium">Comissão</th>
                    <th scope="col" class="px-4 py-3 text-right font-medium">Margem</th>
                    <th scope="col" class="px-4 py-3 text-right font-medium">%</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($rows as $row)
                    @php $margin = $net($row); @endphp
                    <tr>
                        <td class="px-4 py-3 font-medium">
                            {{ $row->name }}
                            @if ($row->uncosted > 0)
                                <span class="block text-xs font-normal text-amber-800">Há produto sem custo de entrada: o custo está subestimado.</span>
                            @endif
                        </td>
                        <td data-label="Atendimentos" class="px-4 py-3 text-right tabular-nums">{{ $row->treatments }}</td>
                        <td data-label="Receita" class="px-4 py-3 text-right tabular-nums">{{ LocalizedDecimal::money($row->revenue) }}</td>
                        <td data-label="Descontos" class="px-4 py-3 text-right tabular-nums text-slate-600">{{ LocalizedDecimal::money($row->discount) }}</td>
                        <td data-label="Estornos" class="px-4 py-3 text-right tabular-nums text-slate-600">{{ LocalizedDecimal::money($row->refunded) }}</td>
                        <td data-label="Insumos" class="px-4 py-3 text-right tabular-nums">{{ LocalizedDecimal::money($row->cost) }}</td>
                        <td data-label="Comissão" class="px-4 py-3 text-right tabular-nums">{{ LocalizedDecimal::money($row->commission) }}</td>
                        <td data-label="Margem" @class(['px-4 py-3 text-right font-medium tabular-nums', 'text-red-700' => $margin < 0])>{{ LocalizedDecimal::money($margin) }}</td>
                        <td data-label="%" class="px-4 py-3 text-right tabular-nums">{{ $percent($row->revenue, $margin) }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="9" class="px-4 py-10 text-center text-slate-500">Nenhum atendimento registrado no período.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <p class="mt-4 text-xs text-slate-500">
        Receita: a cobrança do atendimento ou, em sessão de pacote, o valor da sessão no pacote. Descontos: sobre o preço de tabela, já fora da receita.
        Margem: receita menos estornos, insumos (custo médio dos produtos baixados, inclusive a sobra descartada) e comissão devida hoje.
        Não entram impostos nem taxas de cartão. Atendimentos anulados ficam de fora.
    </p>
</x-finance.page>
