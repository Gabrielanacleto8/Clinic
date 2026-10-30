@php
    use App\Support\LocalizedDecimal;

    $monthLabel = fn ($date) => ucfirst($date->locale('pt_BR')->translatedFormat('F \d\e Y'));
    $shortLabel = fn ($date) => ucfirst($date->locale('pt_BR')->translatedFormat('M/y'));
    $series = collect($months);
    $first = Carbon\CarbonImmutable::createFromFormat('!Y-m', array_key_first($months));
    $outflow = fn (object $month) => $month->expenses + $month->fees;
    $largest = max(1, $series->max(fn ($month) => max($month->issued, $month->received, $outflow($month))));

    $sum = fn (string $field, bool $before = false) => $series->sum(fn ($month) => $before ? $month->before->{$field} : $month->{$field});
    $totals = [
        'Faturamento (lançado)' => ['issued', 'Receitas pela data do lançamento'],
        'Recebido' => ['received', 'Receitas pagas, pela data do pagamento'],
        'Despesas pagas' => ['outflow', 'Inclui as taxas da conciliação'],
        'Resultado' => ['result', 'Recebido menos despesas e taxas'],
    ];
    $total = fn (string $key, bool $before = false) => $key === 'outflow' ? $sum('expenses', $before) + $sum('fees', $before) : $sum($key, $before);

    // Variação contra o mesmo trecho do ano anterior; sem base, não há porcentagem.
    $change = function (float $now, float $before): ?string {
        if ($before == 0.0) {
            return null;
        }

        $percent = ($now - $before) / abs($before) * 100;

        return ($percent >= 0 ? '+' : '−').number_format(abs($percent), 0, ',', '.').'%';
    };
@endphp
<x-finance.page active="comparativo" title="Comparativo mensal"
                :subtitle="$monthLabel($first).' a '.$monthLabel($lastMonth)">
    <form method="GET" action="{{ route('finance.analysis') }}" class="mt-4 grid grid-cols-2 gap-2 sm:grid-cols-12" role="search">
        <div class="sm:col-span-3">
            <label for="mes" class="sr-only">Até o mês</label>
            <input id="mes" name="mes" type="month" value="{{ $lastMonth->format('Y-m') }}" class="input" required>
        </div>
        <div class="sm:col-span-3">
            <label for="meses" class="sr-only">Quantidade de meses</label>
            <select id="meses" name="meses" class="input">
                @foreach ($windows as $option)
                    <option value="{{ $option }}" @selected($window === $option)>Últimos {{ $option }} meses</option>
                @endforeach
            </select>
        </div>
        <button type="submit" class="btn btn-secondary col-span-2 sm:col-span-2">Filtrar</button>
        @foreach (['mes', 'meses'] as $field)
            @error($field)
                <p class="col-span-2 text-sm text-red-700 sm:col-span-12">{{ $message }}</p>
            @enderror
        @endforeach
    </form>

    <dl class="mt-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
        @foreach ($totals as $label => [$key, $caption])
            @php
                $now = $total($key);
                $variation = $change($now, $total($key, true));
            @endphp
            <div class="card px-4 py-3">
                <dt class="text-xs text-slate-500">{{ $label }}</dt>
                <dd @class(['mt-0.5 text-xl font-semibold tabular-nums', 'text-red-700' => $now < 0])>{{ LocalizedDecimal::money($now) }}</dd>
                <dd class="mt-0.5 text-xs text-slate-500">{{ $caption }}</dd>
                @if ($variation)
                    <dd class="mt-0.5 text-xs text-slate-400 tabular-nums">{{ $variation }} vs mesmos meses do ano anterior</dd>
                @endif
            </div>
        @endforeach
    </dl>

    <section class="card mt-6 p-6" aria-labelledby="grafico">
        <h2 id="grafico" class="text-sm font-semibold tracking-wide text-slate-500 uppercase">Receitas e despesas por mês</h2>
        <p class="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-xs text-slate-600" aria-hidden="true">
            <span class="flex items-center gap-1.5"><span class="size-2 rounded-full bg-slate-600"></span>Lançado</span>
            <span class="flex items-center gap-1.5"><span class="size-2 rounded-full bg-emerald-400"></span>Recebido</span>
            <span class="flex items-center gap-1.5"><span class="size-2 rounded-full bg-red-400"></span>Despesas pagas</span>
        </p>
        <ul class="mt-3 divide-y divide-slate-100 text-sm">
            @foreach ($months as $period => $month)
                @php $date = Carbon\CarbonImmutable::createFromFormat('!Y-m', $period); @endphp
                <li class="grid grid-cols-1 gap-x-4 gap-y-1.5 py-2.5 sm:grid-cols-[8rem_minmax(0,1fr)_10rem] sm:items-center">
                    <a href="{{ route('finance.index', ['mes' => $period]) }}" class="text-slate-800 hover:underline">{{ $monthLabel($date) }}</a>
                    <div class="space-y-1">
                        <progress class="bar text-slate-600" max="{{ $largest }}" value="{{ $month->issued }}" aria-label="Lançado em {{ $monthLabel($date) }}">{{ LocalizedDecimal::money($month->issued) }}</progress>
                        <progress class="bar text-emerald-400" max="{{ $largest }}" value="{{ $month->received }}" aria-label="Recebido em {{ $monthLabel($date) }}">{{ LocalizedDecimal::money($month->received) }}</progress>
                        <progress class="bar text-red-400" max="{{ $largest }}" value="{{ $outflow($month) }}" aria-label="Despesas pagas em {{ $monthLabel($date) }}">{{ LocalizedDecimal::money($outflow($month)) }}</progress>
                    </div>
                    <p class="text-xs text-slate-600 tabular-nums sm:text-right">
                        Resultado
                        <span @class(['font-medium', 'text-slate-900' => $month->result >= 0, 'text-red-700' => $month->result < 0])>{{ LocalizedDecimal::money($month->result) }}</span>
                    </p>
                </li>
            @endforeach
        </ul>
    </section>

    <section class="card mt-6 relative overflow-x-auto" aria-labelledby="tabela">
        <h2 id="tabela" class="px-4 pt-5 text-sm font-semibold tracking-wide text-slate-500 uppercase sm:px-6">Mês a mês, contra o ano anterior</h2>
        <table class="table-stack mt-3 w-full min-w-3xl text-left text-sm">
            <thead class="border-y border-slate-200 bg-slate-50 text-xs tracking-wide text-slate-500 uppercase">
                <tr>
                    <th scope="col" class="px-4 py-3 font-medium sm:px-6">Mês</th>
                    <th scope="col" class="px-4 py-3 text-right font-medium">Lançado</th>
                    <th scope="col" class="px-4 py-3 text-right font-medium">Recebido</th>
                    <th scope="col" class="px-4 py-3 text-right font-medium">Despesas</th>
                    <th scope="col" class="px-4 py-3 text-right font-medium">Taxas</th>
                    <th scope="col" class="px-4 py-3 text-right font-medium">Resultado</th>
                    <th scope="col" class="px-4 py-3 text-right font-medium sm:px-6">Lançado vs ano anterior</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @foreach (array_reverse($months, true) as $period => $month)
                    @php
                        $date = Carbon\CarbonImmutable::createFromFormat('!Y-m', $period);
                        $yearChange = $change($month->issued, $month->before->issued);
                    @endphp
                    <tr>
                        <th scope="row" class="px-4 py-3 font-normal sm:px-6">{{ $monthLabel($date) }}</th>
                        <td data-label="Lançado" class="px-4 py-3 text-right tabular-nums">{{ LocalizedDecimal::money($month->issued) }}</td>
                        <td data-label="Recebido" class="px-4 py-3 text-right tabular-nums">{{ LocalizedDecimal::money($month->received) }}</td>
                        <td data-label="Despesas" class="px-4 py-3 text-right tabular-nums">{{ LocalizedDecimal::money($month->expenses) }}</td>
                        <td data-label="Taxas" class="px-4 py-3 text-right tabular-nums text-slate-600">{{ LocalizedDecimal::money($month->fees) }}</td>
                        <td data-label="Resultado" @class(['px-4 py-3 text-right font-medium tabular-nums', 'text-red-700' => $month->result < 0])>{{ LocalizedDecimal::money($month->result) }}</td>
                        <td data-label="Lançado vs ano anterior" class="px-4 py-3 text-right tabular-nums sm:px-6">
                            @if ($yearChange)
                                <span @class(['text-emerald-700' => $month->issued >= $month->before->issued, 'text-red-700' => $month->issued < $month->before->issued])>{{ $yearChange }}</span>
                                <span class="text-xs text-slate-500">vs {{ $shortLabel($date->subYear()) }}</span>
                            @else
                                <span class="text-slate-400">—</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </section>

    <p class="mt-4 text-xs text-slate-500">
        Lançado: receitas pela data do lançamento, pagas ou não. Recebido, despesas e taxas: pela data em que o dinheiro entrou ou saiu, como no fluxo de caixa;
        despesas incluem estornos a pacientes e repasses de comissão. Taxas: descontadas pela operadora, no mês do extrato.
    </p>
</x-finance.page>
