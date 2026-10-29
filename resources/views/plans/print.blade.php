@php
    use App\Support\ClinicTime;
    use App\Support\LocalizedDecimal;
@endphp
<x-layouts.print :title="'Orçamento nº '.$plan->id.' · '.$patient->name" :back="route('patients.plans.show', [$patient, $plan])">
    <x-clinic-header :profile="$clinic" document="Orçamento" class="border-b border-slate-300 pb-4" />

    <div class="mt-6 flex flex-wrap justify-between gap-2">
        <div>
            <h1 class="text-lg font-semibold">Orçamento nº {{ $plan->id }}</h1>
            <p class="text-slate-600">Paciente: {{ $patient->name }}</p>
        </div>
        <div class="text-right text-slate-600">
            <p>Emitido em {{ ClinicTime::format($plan->created_at, 'd/m/Y') }}</p>
            @if ($plan->valid_until)
                <p>Válido até {{ $plan->valid_until->format('d/m/Y') }}</p>
            @endif
        </div>
    </div>

    <table class="table-stack mt-6 w-full text-left">
        <thead class="border-b border-slate-300 text-xs text-slate-500">
            <tr>
                <th scope="col" class="py-1 font-medium">Procedimento</th>
                <th scope="col" class="py-1 text-right font-medium">Sessões</th>
                <th scope="col" class="py-1 text-right font-medium">Por sessão</th>
                <th scope="col" class="py-1 text-right font-medium">Subtotal</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-200">
            @foreach ($plan->items as $item)
                <tr>
                    <td class="py-1.5">{{ $item->procedure->name }}</td>
                    <td data-label="Sessões" class="py-1.5 text-right tabular-nums">{{ $item->sessions }}</td>
                    <td data-label="Por sessão" class="py-1.5 text-right tabular-nums">{{ LocalizedDecimal::money($item->unit_price) }}</td>
                    <td data-label="Subtotal" class="py-1.5 text-right tabular-nums">{{ LocalizedDecimal::money($item->sessions * (float) $item->unit_price) }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot class="border-t border-slate-300">
            <tr>
                <td colspan="3" class="py-2 font-medium">Total</td>
                <td class="py-2 text-right text-base font-semibold tabular-nums">{{ LocalizedDecimal::money($plan->total()) }}</td>
            </tr>
        </tfoot>
    </table>

    @if ($plan->notes)
        <section class="mt-6">
            <h2 class="text-xs font-semibold tracking-wide text-slate-500 uppercase">Observações</h2>
            <p class="mt-1 whitespace-pre-line">{{ $plan->notes }}</p>
        </section>
    @endif

    <p class="mt-6 text-xs text-slate-500">Forma de pagamento e parcelamento são combinados no fechamento da venda. Os valores valem até a data indicada.</p>

    <div class="mt-16 grid grid-cols-1 gap-12 sm:grid-cols-2 print:grid-cols-2">
        <div class="border-t border-slate-500 pt-1 text-center text-xs">{{ $patient->name }}</div>
        <div class="border-t border-slate-500 pt-1 text-center text-xs">{{ $plan->creator?->name ?? 'Responsável pela clínica' }}</div>
    </div>
</x-layouts.print>
