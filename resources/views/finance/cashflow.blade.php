@php
    use App\Support\LocalizedDecimal;

    $filters = ['de' => $start, 'ate' => $end];
    $format = fn (string $date) => Carbon\CarbonImmutable::createFromFormat('!Y-m-d', $date)->format('d/m/Y');
    $signed = fn (float $value) => ($value < 0 ? '−' : '').LocalizedDecimal::money(abs($value));
@endphp
<x-finance.page active="fluxo" title="Fluxo de caixa" :subtitle="$format($start).' a '.$format($end)">
    <form method="GET" action="{{ route('finance.cashflow') }}" class="mt-4 grid grid-cols-2 gap-2 sm:grid-cols-12" role="search">
        @include('stock._period_fields', ['span' => 'sm:col-span-4'])
        <button type="submit" class="btn btn-secondary col-span-2 sm:col-span-2">Filtrar</button>
        @foreach (['mes', 'de', 'ate'] as $field)
            @error($field)
                <p class="col-span-2 text-sm text-red-700 sm:col-span-12">{{ $message }}</p>
            @enderror
        @endforeach
    </form>

    <dl class="mt-6 grid grid-cols-2 gap-3 lg:grid-cols-5">
        @foreach ([
            'Saldo inicial' => [$opening, 'Tudo o que entrou e saiu antes do período'],
            'Entradas' => [$totals->in, 'Receitas pagas'],
            'Saídas' => [$totals->out + $totals->fees, 'Despesas, estornos, repasses e taxas'],
            'Saldo final' => [$closing, 'Saldo inicial + entradas − saídas'],
            'Saldo projetado' => [$projected, 'Com o que ainda vence no período'],
        ] as $label => [$value, $caption])
            <div class="card px-4 py-3">
                <dt class="text-xs text-slate-500">{{ $label }}</dt>
                <dd @class(['mt-0.5 text-lg font-semibold tabular-nums', 'text-red-700' => $value < 0])>{{ $signed($value) }}</dd>
                <dd class="text-xs text-slate-500">{{ $caption }}</dd>
            </div>
        @endforeach
    </dl>

    <section class="card mt-6 relative overflow-x-auto" aria-labelledby="dia-a-dia">
        <h2 id="dia-a-dia" class="px-4 pt-5 text-sm font-semibold tracking-wide text-slate-500 uppercase sm:px-6">Dia a dia</h2>
        <p class="px-4 pt-1 text-xs text-slate-500 sm:px-6">Realizado pela data do pagamento; taxas pela data em que o valor caiu no extrato. Previsto: parcelas em aberto pelo vencimento.</p>
        @if ($days === [])
            <p class="px-4 pt-4 pb-6 text-sm text-slate-600 sm:px-6">Nenhum movimento nem vencimento neste período.</p>
        @else
            <table class="table-stack mt-3 w-full min-w-3xl text-left text-sm">
                <thead class="border-y border-slate-200 bg-slate-50 text-xs tracking-wide text-slate-500 uppercase">
                    <tr>
                        <th scope="col" class="px-4 py-3 font-medium sm:px-6">Dia</th>
                        <th scope="col" class="px-4 py-3 text-right font-medium">Entradas</th>
                        <th scope="col" class="px-4 py-3 text-right font-medium">Saídas</th>
                        <th scope="col" class="px-4 py-3 text-right font-medium">Taxas</th>
                        <th scope="col" class="px-4 py-3 text-right font-medium">Saldo acumulado</th>
                        <th scope="col" class="px-4 py-3 text-right font-medium">A receber previsto</th>
                        <th scope="col" class="px-4 py-3 text-right font-medium sm:px-6">A pagar previsto</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($days as $row)
                        <tr>
                            <th scope="row" class="px-4 py-3 font-medium whitespace-nowrap tabular-nums sm:px-6">{{ $row->day->format('d/m') }} <span class="font-normal text-slate-500">{{ $row->day->locale('pt_BR')->translatedFormat('D') }}</span></th>
                            <td data-label="Entradas" class="px-4 py-3 text-right tabular-nums text-emerald-700">{{ $row->in ? LocalizedDecimal::money($row->in) : '—' }}</td>
                            <td data-label="Saídas" class="px-4 py-3 text-right tabular-nums text-red-700">{{ $row->out ? LocalizedDecimal::money($row->out) : '—' }}</td>
                            <td data-label="Taxas" class="px-4 py-3 text-right tabular-nums text-red-700">{{ $row->fees ? LocalizedDecimal::money($row->fees) : '—' }}</td>
                            <td data-label="Saldo acumulado" @class(['px-4 py-3 text-right font-semibold tabular-nums', 'text-red-700' => $row->balance < 0])>{{ $signed($row->balance) }}</td>
                            <td data-label="A receber previsto" class="px-4 py-3 text-right tabular-nums text-slate-600">{{ $row->planned_in ? LocalizedDecimal::money($row->planned_in) : '—' }}</td>
                            <td data-label="A pagar previsto" class="px-4 py-3 text-right tabular-nums text-slate-600 sm:px-6">{{ $row->planned_out ? LocalizedDecimal::money($row->planned_out) : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot class="border-t border-slate-200 bg-slate-50 text-xs">
                    <tr>
                        <td class="px-4 py-2 text-slate-600 sm:px-6">Total</td>
                        <td class="px-4 py-2 text-right font-semibold tabular-nums">{{ LocalizedDecimal::money($totals->in) }}</td>
                        <td class="px-4 py-2 text-right font-semibold tabular-nums">{{ LocalizedDecimal::money($totals->out) }}</td>
                        <td class="px-4 py-2 text-right font-semibold tabular-nums">{{ LocalizedDecimal::money($totals->fees) }}</td>
                        <td class="px-4 py-2 text-right font-semibold tabular-nums">{{ $signed($closing) }}</td>
                        <td class="px-4 py-2 text-right font-semibold tabular-nums">{{ LocalizedDecimal::money($totals->planned_in) }}</td>
                        <td class="px-4 py-2 text-right font-semibold tabular-nums sm:px-6">{{ LocalizedDecimal::money($totals->planned_out) }}</td>
                    </tr>
                </tfoot>
            </table>
        @endif
    </section>
</x-finance.page>
