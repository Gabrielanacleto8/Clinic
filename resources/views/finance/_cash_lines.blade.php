@php use App\Support\LocalizedDecimal; @endphp
{{-- Esperado e conferido por forma de pagamento. Linhas com "counted" nulo ainda não foram conferidas. --}}
<div class="mt-4 relative overflow-x-auto">
    <table class="table-stack w-full min-w-xl text-left text-sm">
        <thead class="border-y border-slate-200 bg-slate-50 text-xs tracking-wide text-slate-500 uppercase">
            <tr>
                <th scope="col" class="px-4 py-3 font-medium">Forma</th>
                <th scope="col" class="px-4 py-3 text-right font-medium">Esperado</th>
                <th scope="col" class="px-4 py-3 text-right font-medium">Conferido</th>
                <th scope="col" class="px-4 py-3 text-right font-medium">Diferença</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            @foreach ($lines as $line)
                @php $difference = $line->counted === null ? null : round((float) $line->counted - (float) $line->expected, 2); @endphp
                <tr>
                    <th scope="row" class="px-4 py-3 font-medium">{{ $line->payment_method->label() }}</th>
                    <td data-label="Esperado" class="px-4 py-3 text-right tabular-nums">{{ LocalizedDecimal::money($line->expected) }}</td>
                    <td data-label="Conferido" class="px-4 py-3 text-right tabular-nums">{{ $line->counted === null ? '—' : LocalizedDecimal::money($line->counted) }}</td>
                    <td data-label="Diferença" @class(['px-4 py-3 text-right font-medium tabular-nums', 'text-red-700' => $difference < 0, 'text-amber-700' => $difference > 0])>
                        {{ $difference === null ? '—' : ($difference == 0 ? 'ok' : ($difference > 0 ? '+' : '−').' '.LocalizedDecimal::money(abs($difference))) }}
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
