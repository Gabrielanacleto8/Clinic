@php use App\Support\LocalizedDecimal; @endphp
<x-finance.page active="inadimplencia" title="Inadimplência"
                :subtitle="LocalizedDecimal::money($summary->total).' vencido e não recebido, de '.App\Support\Plural::of((int) $summary->patients, 'paciente', 'pacientes')">
    <dl class="mt-6 grid grid-cols-2 gap-3 lg:grid-cols-4">
        @foreach ($aging as $index => $label)
            <div class="card px-4 py-3">
                <dt class="text-xs text-slate-500">{{ $label }} de atraso</dt>
                <dd @class(['mt-0.5 text-lg font-semibold tabular-nums', 'text-red-700' => $index >= 2 && $summary->{'aging_'.$index} > 0])>{{ LocalizedDecimal::money($summary->{'aging_'.$index}) }}</dd>
            </div>
        @endforeach
    </dl>

    @if ((float) $summary->without_patient > 0)
        <p class="mt-3 text-sm text-slate-600">
            Inclui {{ LocalizedDecimal::money($summary->without_patient) }} em receitas vencidas sem paciente ligado, que não aparecem na lista abaixo.
            <a href="{{ route('finance.receivable', ['faixa' => 'vencidas']) }}" class="text-teal-800 hover:underline">Ver todas as vencidas</a>
        </p>
    @endif

    <section class="card mt-6 relative overflow-x-auto" aria-labelledby="devedores">
        <h2 id="devedores" class="px-4 pt-5 text-sm font-semibold tracking-wide text-slate-500 uppercase sm:px-6">Pacientes com cobrança vencida</h2>
        @if ($patients->isEmpty())
            <p class="px-4 pt-4 pb-6 text-sm text-slate-600 sm:px-6">Nenhum paciente com cobrança vencida.</p>
        @else
            <table class="table-stack mt-3 w-full min-w-3xl text-left text-sm">
                <thead class="border-y border-slate-200 bg-slate-50 text-xs tracking-wide text-slate-500 uppercase">
                    <tr>
                        <th scope="col" class="px-4 py-3 font-medium sm:px-6">Paciente</th>
                        <th scope="col" class="px-4 py-3 text-right font-medium">Vencido</th>
                        <th scope="col" class="px-4 py-3 text-right font-medium">Parcelas</th>
                        <th scope="col" class="px-4 py-3 text-right font-medium">Maior atraso</th>
                        @foreach ($aging as $label)
                            <th scope="col" class="px-4 py-3 text-right font-medium">{{ $label }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($patients as $row)
                        <tr>
                            <td data-label="Paciente" class="px-4 py-3 sm:px-6">
                                <a href="{{ route('patients.show', [$row->patient_id, 'aba' => 'financeiro']) }}" class="font-medium text-teal-800 hover:underline">{{ $names[$row->patient_id] ?? 'Paciente nº '.$row->patient_id }}</a>
                            </td>
                            <td data-label="Vencido" class="px-4 py-3 text-right font-semibold text-red-700 tabular-nums">{{ LocalizedDecimal::money($row->total) }}</td>
                            <td data-label="Parcelas" class="px-4 py-3 text-right tabular-nums">{{ $row->installments }}</td>
                            <td data-label="Maior atraso" class="px-4 py-3 text-right whitespace-nowrap tabular-nums">{{ App\Support\Plural::of((int) $row->oldest, 'dia', 'dias') }}</td>
                            @foreach ($aging as $index => $label)
                                @php $value = (float) $row->{'aging_'.$index}; @endphp
                                <td data-label="{{ $label }}" class="px-4 py-3 text-right tabular-nums text-slate-600" @if ($value == 0) data-empty @endif>{{ $value > 0 ? LocalizedDecimal::money($value) : '—' }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </section>

    <div class="mt-4">
        {{ $patients->links() }}
    </div>
</x-finance.page>
