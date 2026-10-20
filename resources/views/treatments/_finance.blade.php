@php use App\Support\LocalizedDecimal; @endphp
{{-- Cobrança do atendimento. $entry é nulo para quem não vê o financeiro. --}}
@if ($entry)
    <section class="card mt-6 relative overflow-x-auto" aria-labelledby="financeiro">
        <h2 id="financeiro" class="px-6 pt-6 text-sm font-semibold tracking-wide text-slate-500 uppercase">Financeiro</h2>
        <p class="px-6 pt-1 text-sm text-slate-600">
            {{ LocalizedDecimal::money($entry->installments->sum('amount')) }} · {{ $entry->payment_method->label() }}
        </p>
        <table class="table-stack mt-4 w-full min-w-md text-left text-sm">
            <tbody class="divide-y divide-slate-100 border-t border-slate-200">
                @foreach ($entry->installments as $installment)
                    @php $badge = $installment->statusBadge(); @endphp
                    <tr>
                        <td data-label="Parcela" class="px-6 py-3 text-slate-600">{{ $entry->installments->count() > 1 ? 'Parcela '.$installment->number.'/'.$entry->installments->count() : 'À vista' }}</td>
                        <td data-label="Vencimento" class="px-4 py-3 text-slate-600 tabular-nums">vence em {{ $installment->due_date->format('d/m/Y') }}</td>
                        <td data-label="Valor" class="px-4 py-3 text-right tabular-nums">{{ LocalizedDecimal::money($installment->amount) }}</td>
                        <td data-label="Situação" class="px-6 py-3 text-right"><span class="badge {{ $badge['classes'] }}">{{ $badge['label'] }}</span></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </section>
@endif
