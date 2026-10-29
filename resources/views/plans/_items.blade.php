@php
    use App\Support\ClinicTime;
    use App\Support\LocalizedDecimal;
    use App\Support\Plural;

    // Procedimentos do orçamento, na página dele e na janela da lista. Espera os
    // itens com "used_sessions" e o procedimento; as datas das sessões, se carregadas.
    $sold = $plan->wasSold();
    $heading = 'text-xs font-semibold tracking-wide text-slate-500 uppercase';
@endphp
<section class="card" aria-labelledby="itens">
    <h2 id="itens" class="{{ $heading }} px-5 pt-5">Procedimentos</h2>
    <ul class="mt-3 divide-y divide-slate-100">
        @foreach ($plan->items as $item)
            @php $used = (int) $item->used_sessions; @endphp
            <li class="flex flex-wrap items-start justify-between gap-x-6 gap-y-2 px-5 py-4">
                <div class="min-w-0 flex-1 basis-56">
                    <p class="font-medium">{{ $item->procedure->name }}</p>
                    <p class="mt-0.5 text-sm text-slate-600 tabular-nums">
                        {{ Plural::of($item->sessions, 'sessão', 'sessões') }} × {{ LocalizedDecimal::money($item->unit_price) }}
                    </p>
                    @if ($sold)
                        <div class="mt-2 flex items-center gap-3">
                            <progress class="bar max-w-40 text-teal-600" max="{{ max($item->sessions, 1) }}" value="{{ $used }}" aria-label="Sessões usadas de {{ $item->procedure->name }}">{{ $used }}</progress>
                            <span class="shrink-0 text-xs text-slate-500 tabular-nums">{{ $used }} de {{ $item->sessions }} usadas</span>
                        </div>
                    @endif
                    @if ($item->relationLoaded('treatments') && $item->treatments->isNotEmpty())
                        <p class="mt-1.5 flex flex-wrap gap-1.5 text-xs">
                            @foreach ($item->treatments as $treatment)
                                <a href="{{ route('patients.treatments.show', [$patient, $treatment]) }}"
                                   @class(['rounded-md bg-slate-100 px-2 py-0.5 tabular-nums text-slate-700 hover:bg-slate-200', 'line-through opacity-70' => $treatment->voided_at])
                                   @if ($treatment->voided_at) title="Atendimento anulado" @endif>{{ ClinicTime::format($treatment->performed_at, 'd/m/Y') }}</a>
                            @endforeach
                        </p>
                    @endif
                </div>
                <p class="shrink-0 text-right font-semibold tabular-nums">{{ LocalizedDecimal::money($item->sessions * (float) $item->unit_price) }}</p>
            </li>
        @endforeach
    </ul>
    <div class="flex items-baseline justify-between gap-4 rounded-b-xl border-t border-slate-200 bg-slate-50 px-5 py-3">
        <span class="text-sm font-medium text-slate-700">Total</span>
        <span class="text-lg font-semibold tabular-nums">{{ LocalizedDecimal::money($plan->total()) }}</span>
    </div>
</section>
