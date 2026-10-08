@php use App\Support\LocalizedDecimal; @endphp
<dl class="mt-4 grid grid-cols-2 gap-4">
    <div class="card px-4 py-3">
        <dt class="text-xs text-slate-500">Total pago</dt>
        <dd class="mt-0.5 text-xl font-semibold tabular-nums">{{ LocalizedDecimal::money($financialTotals->paid) }}</dd>
    </div>
    <div class="card px-4 py-3">
        <dt class="text-xs text-slate-500">Total pendente</dt>
        <dd @class(['mt-0.5 text-xl font-semibold tabular-nums', 'text-amber-700' => (float) $financialTotals->pending > 0])>{{ LocalizedDecimal::money($financialTotals->pending) }}</dd>
    </div>
</dl>

<section class="card mt-4 overflow-x-auto" aria-labelledby="financeiro-do-paciente">
    <h2 id="financeiro-do-paciente" class="sr-only">Histórico financeiro</h2>
    @if ($records->isEmpty())
        <p class="px-4 py-10 text-center text-sm text-slate-500">Nenhum lançamento financeiro ligado a este paciente.</p>
    @else
        <table class="w-full min-w-2xl text-left text-sm">
            <thead class="border-b border-slate-200 bg-slate-50 text-xs tracking-wide text-slate-500 uppercase">
                <tr>
                    <th scope="col" class="px-4 py-3 font-medium">Vencimento</th>
                    <th scope="col" class="px-4 py-3 font-medium">Descrição</th>
                    <th scope="col" class="px-4 py-3 text-right font-medium">Valor</th>
                    <th scope="col" class="px-4 py-3 font-medium">Situação</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @foreach ($records as $installment)
                    @php
                        $entry = $installment->entry;
                        $badge = $installment->statusBadge();
                    @endphp
                    <tr>
                        <td class="px-4 py-3 whitespace-nowrap text-slate-600 tabular-nums">{{ $installment->due_date->format('d/m/Y') }}</td>
                        <td class="px-4 py-3">
                            @if ($entry->treatment_id)
                                <a href="{{ route('patients.treatments.show', [$patient, $entry->treatment_id]) }}" class="font-medium text-teal-800 hover:underline">{{ $entry->description }}</a>
                            @else
                                <span class="font-medium">{{ $entry->description }}</span>
                            @endif
                            <span class="block text-xs text-slate-500">
                                {{ $entry->payment_method->label() }} · {{ $entry->installments_count > 1 ? 'Parcela '.$installment->number.'/'.$entry->installments_count : 'À vista' }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-right whitespace-nowrap tabular-nums">{{ LocalizedDecimal::money($installment->amount) }}</td>
                        <td class="px-4 py-3 whitespace-nowrap">
                            <span class="badge {{ $badge['classes'] }}">{{ $badge['label'] }}</span>
                            @if ($installment->isPaid())
                                <span class="ml-1 text-xs text-slate-500">em {{ $installment->paid_at->format('d/m/Y') }}</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</section>

<div class="mt-4">{{ $records->links() }}</div>
