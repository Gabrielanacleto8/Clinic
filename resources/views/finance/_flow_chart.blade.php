@php
    use App\Support\AreaChart;
    use App\Support\LocalizedDecimal;

    // Recebido e despesas pagas (com as taxas da conciliação) de cada mês: o que entrou e saiu do caixa.
    $dates = array_map(fn (string $period) => Carbon\CarbonImmutable::createFromFormat('!Y-m', $period)->locale('pt_BR'), array_keys($flow));
    $received = array_map(fn (object $month) => $month->received, array_values($flow));
    $outflow = array_map(fn (object $month) => $month->expenses + $month->fees, array_values($flow));
    $chart = new AreaChart(array_map(fn ($date) => mb_strtoupper($date->translatedFormat('M')), $dates), ['received' => $received, 'outflow' => $outflow]);
    $series = [
        'received' => ['Recebido', 'bg-sky-600', 'stroke-sky-600'],
        'outflow' => ['Despesas pagas', 'bg-slate-700', 'stroke-slate-700'],
    ];
    $fullLabel = fn ($date) => ucfirst($date->translatedFormat('F \d\e Y'));

    // Lido pelo app.js para a linha de foco e a caixa de valores; os mesmos números estão na tabela abaixo.
    $points = array_map(fn (int $index) => [
        'label' => $fullLabel($dates[$index]),
        'x' => round($chart->xPercent($index), 3),
        'y' => array_map(fn (string $key) => round($chart->yPercent($chart->series[$key][$index]), 3), array_keys($series)),
        'values' => array_map(fn (string $key) => LocalizedDecimal::money($chart->series[$key][$index]), array_keys($series)),
    ], array_keys($dates));
    $columns = [6 => 'grid-cols-6', 12 => 'grid-cols-12'][count($dates)] ?? 'grid-cols-6';
@endphp
<section class="card mt-6 p-6" aria-labelledby="receitas-despesas">
    <div class="flex flex-wrap items-start justify-between gap-x-6 gap-y-3">
        <div>
            <h2 id="receitas-despesas" class="text-base font-semibold text-slate-900">Receitas e despesas</h2>
            <p class="mt-0.5 text-sm text-slate-500">{{ $fullLabel($dates[0]) }} a {{ $fullLabel(end($dates)) }}, pelo que entrou e saiu do caixa</p>
        </div>
        <ul class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-slate-600">
            @foreach ($series as [$label, $swatch])
                <li class="flex items-center gap-1.5"><span class="size-2.5 rounded-sm {{ $swatch }}" aria-hidden="true"></span>{{ $label }}</li>
            @endforeach
        </ul>
    </div>

    <div class="mt-6 grid grid-cols-[auto_minmax(0,1fr)] gap-x-3">
        {{-- Rótulos do eixo: um por linha de grade, centrados nela (a coluna tem a altura
             do gráfico mais uma linha de texto, metade acima e metade abaixo). --}}
        <div class="-my-2 flex h-60 flex-col justify-between text-right text-xs text-slate-500 tabular-nums sm:h-68" aria-hidden="true">
            @foreach ($chart->ticks() as $tick)
                <span>{{ AreaChart::compactMoney($tick) }}</span>
            @endforeach
        </div>

        <div class="relative h-56 cursor-crosshair rounded-sm focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-slate-700 sm:h-64"
             data-area-chart data-points="{{ json_encode($points) }}" tabindex="0"
             aria-label="Gráfico de recebido e despesas pagas por mês. Use as setas para percorrer os meses.">
            <svg class="absolute inset-0 size-full overflow-visible" viewBox="0 0 {{ AreaChart::WIDTH }} {{ AreaChart::HEIGHT }}" preserveAspectRatio="none" aria-hidden="true">
                <defs>
                    <linearGradient id="area-received" x1="0" y1="0" x2="0" y2="1" class="text-sky-500">
                        <stop offset="0" stop-color="currentColor" stop-opacity="0.28" />
                        <stop offset="1" stop-color="currentColor" stop-opacity="0.03" />
                    </linearGradient>
                    <linearGradient id="area-outflow" x1="0" y1="0" x2="0" y2="1" class="text-slate-500">
                        <stop offset="0" stop-color="currentColor" stop-opacity="0.16" />
                        <stop offset="1" stop-color="currentColor" stop-opacity="0.02" />
                    </linearGradient>
                </defs>
                @foreach ($chart->ticks() as $tick)
                    <line x1="0" x2="{{ AreaChart::WIDTH }}" y1="{{ $chart->y($tick) }}" y2="{{ $chart->y($tick) }}" class="stroke-slate-200" stroke-width="1" vector-effect="non-scaling-stroke" />
                @endforeach
                @foreach ($series as $key => $style)
                    <path d="{{ $chart->area($key) }}" fill="url(#area-{{ $key }})" />
                @endforeach
                @foreach ($series as $key => [, , $stroke])
                    <path d="{{ $chart->line($key) }}" fill="none" class="{{ $stroke }}" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke" />
                @endforeach
            </svg>

            <div class="pointer-events-none absolute inset-y-0 hidden w-px bg-slate-400" data-chart-crosshair></div>
            @foreach ($series as [, $swatch])
                <span class="pointer-events-none absolute hidden size-3 -translate-x-1/2 -translate-y-1/2 rounded-full border-2 border-white shadow-sm {{ $swatch }}" data-chart-dot></span>
            @endforeach

            <div class="pointer-events-none absolute top-2 z-10 hidden min-w-48 rounded-lg border border-slate-200 bg-white p-3 text-xs shadow-lg" data-chart-tooltip>
                <p class="flex items-center gap-1.5 text-slate-500">
                    <x-icon name="calendar" class="size-3.5" />
                    <span data-chart-label></span>
                </p>
                <dl class="mt-2 space-y-1.5 border-t border-slate-100 pt-2">
                    @foreach ($series as [$label, $swatch])
                        <div class="flex items-center justify-between gap-4">
                            <dt class="flex items-center gap-1.5 text-slate-500"><span class="h-0.5 w-3 rounded-full {{ $swatch }}" aria-hidden="true"></span>{{ $label }}</dt>
                            <dd class="font-semibold text-slate-900 tabular-nums" data-chart-value></dd>
                        </div>
                    @endforeach
                </dl>
            </div>
        </div>

        <div></div>
        <ol class="mt-3 grid {{ $columns }} text-center text-xs tracking-wide text-slate-500" aria-hidden="true">
            @foreach ($chart->labels as $label)
                <li>{{ $label }}</li>
            @endforeach
        </ol>
    </div>

    <details class="mt-4 text-sm">
        <summary class="cursor-pointer text-slate-600 hover:text-slate-900">Ver em tabela</summary>
        <div class="mt-3 relative overflow-x-auto">
            <table class="table-stack w-full text-left text-sm">
                <thead class="border-y border-slate-200 bg-slate-50 text-xs tracking-wide text-slate-500 uppercase">
                    <tr>
                        <th scope="col" class="px-3 py-2 font-medium">Mês</th>
                        @foreach ($series as [$label])
                            <th scope="col" class="px-3 py-2 text-right font-medium">{{ $label }}</th>
                        @endforeach
                        <th scope="col" class="px-3 py-2 text-right font-medium">Resultado</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($dates as $index => $date)
                        @php $result = $received[$index] - $outflow[$index]; @endphp
                        <tr>
                            <th scope="row" class="px-3 py-2 font-normal">{{ $fullLabel($date) }}</th>
                            <td data-label="Recebido" class="px-3 py-2 text-right tabular-nums">{{ LocalizedDecimal::money($received[$index]) }}</td>
                            <td data-label="Despesas pagas" class="px-3 py-2 text-right tabular-nums">{{ LocalizedDecimal::money($outflow[$index]) }}</td>
                            <td data-label="Resultado" @class(['px-3 py-2 text-right font-medium tabular-nums', 'text-red-700' => $result < 0])>{{ LocalizedDecimal::money($result) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </details>
</section>
