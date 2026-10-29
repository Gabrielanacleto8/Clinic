@php
    use App\Enums\FinancialEntryType;
    use App\Enums\PaymentMethod;
    use App\Models\FinancialEntry;
    use App\Support\LocalizedDecimal;

    $user = auth()->user();
    $canViewAll = $user->can('viewAny', FinancialEntry::class);
    $date = Carbon\CarbonImmutable::createFromFormat('!Y-m-d', $day);
    $dayLabel = $day === $today ? 'Hoje, '.$date->format('d/m/Y') : ucfirst($date->locale('pt_BR')->translatedFormat('l, d/m/Y'));
    $totalIn = array_sum(array_column($movement, 'in'));
    $totalOut = array_sum(array_column($movement, 'out'));
    // Quem só fecha o caixa vê o detalhe das receitas; despesas entram só no total por forma.
    $listed = $canViewAll ? $payments : $payments->filter(fn ($installment) => $installment->entry->type === FinancialEntryType::Income);
@endphp
<x-finance.page active="caixa" title="Caixa do dia" :subtitle="$dayLabel">
    <form method="GET" action="{{ route('finance.cash.show') }}" class="mt-4 flex flex-wrap items-end gap-2" role="search">
        <div>
            <label for="dia" class="label">Dia</label>
            <input id="dia" name="dia" type="date" value="{{ $day }}" max="{{ $today }}" class="input">
        </div>
        <button type="submit" class="btn btn-secondary">Ver</button>
        @if ($day !== $today)
            <a href="{{ route('finance.cash.show') }}" class="text-sm text-teal-800 hover:underline sm:ml-2">Hoje</a>
        @endif
        @error('dia')
            <p class="w-full text-sm text-red-700">{{ $message }}</p>
        @enderror
    </form>

    @if ($pendingDays !== [])
        <p class="mt-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900" role="note">
            Dias com pagamento e sem fechamento:
            @foreach ($pendingDays as $pending)
                <a href="{{ route('finance.cash.show', ['dia' => $pending]) }}" class="font-medium underline">{{ Carbon\CarbonImmutable::createFromFormat('!Y-m-d', $pending)->format('d/m') }}</a>{{ $loop->last ? '.' : ',' }}
            @endforeach
        </p>
    @endif

    @if ($closing)
        <section class="card mt-6 p-6" aria-labelledby="fechamento">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 id="fechamento" class="text-sm font-semibold tracking-wide text-slate-500 uppercase">Caixa fechado</h2>
                    <p class="mt-1 text-sm text-slate-600">
                        Por {{ $closing->closer?->name ?? 'usuário removido' }} em {{ App\Support\ClinicTime::format($closing->created_at) }}.
                        Pagamentos deste dia não podem mais ser registrados nem desfeitos.
                    </p>
                </div>
                @can('reopenCash', FinancialEntry::class)
                    <form method="POST" action="{{ route('finance.cash.destroy', $closing) }}"
                          data-confirm="Reabrir o caixa de {{ $closing->day->format('d/m/Y') }}? Os pagamentos desse dia voltam a poder ser alterados e o caixa precisa ser fechado de novo.">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn btn-secondary">Reabrir o dia</button>
                    </form>
                @endcan
            </div>
            @include('finance._cash_lines', ['lines' => $closing->lines])
            @if ($closing->notes)
                <p class="mt-3 text-sm text-slate-700"><span class="text-slate-500">Observações:</span> {{ $closing->notes }}</p>
            @endif
        </section>
    @else
        <section class="card mt-6 p-6" aria-labelledby="conferencia">
            <h2 id="conferencia" class="text-sm font-semibold tracking-wide text-slate-500 uppercase">Conferência</h2>
            <p class="mt-1 text-sm text-slate-600">
                Esperado é o que entrou menos o que saiu em cada forma. Conte o dinheiro e confira cartão, Pix e transferências no relatório da maquininha e no extrato.
            </p>

            @can('closeCash', FinancialEntry::class)
                <form method="POST" action="{{ route('finance.cash.store') }}" class="mt-4 space-y-4" novalidate
                      data-confirm="Fechar o caixa de {{ $date->format('d/m/Y') }}? Depois disso, pagamentos deste dia não podem mais ser registrados nem desfeitos.">
                    @csrf
                    <input type="hidden" name="day" value="{{ $day }}">
                    @error('day')
                        <p class="text-sm text-red-700">{{ $message }}</p>
                    @enderror
                    <div class="relative overflow-x-auto">
                        <table class="table-stack w-full min-w-xl text-left text-sm">
                            <thead class="border-y border-slate-200 bg-slate-50 text-xs tracking-wide text-slate-500 uppercase">
                                <tr>
                                    <th scope="col" class="px-4 py-3 font-medium">Forma</th>
                                    <th scope="col" class="px-4 py-3 text-right font-medium">Entradas</th>
                                    <th scope="col" class="px-4 py-3 text-right font-medium">Saídas</th>
                                    <th scope="col" class="px-4 py-3 text-right font-medium">Esperado</th>
                                    <th scope="col" class="px-4 py-3 font-medium">Conferido (R$)</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach ($methods as $method)
                                    @php
                                        $in = $movement[$method->value]['in'] ?? 0;
                                        $out = $movement[$method->value]['out'] ?? 0;
                                        $field = 'counted.'.$method->value;
                                    @endphp
                                    <tr>
                                        <th scope="row" class="px-4 py-3 font-medium">{{ $method->label() }}</th>
                                        <td data-label="Entradas" class="px-4 py-3 text-right tabular-nums text-emerald-700">{{ LocalizedDecimal::money($in) }}</td>
                                        <td data-label="Saídas" class="px-4 py-3 text-right tabular-nums text-red-700">{{ LocalizedDecimal::money($out) }}</td>
                                        <td data-label="Esperado" class="px-4 py-3 text-right font-semibold tabular-nums">{{ LocalizedDecimal::money($in - $out) }}</td>
                                        <td data-label="Conferido (R$)" class="px-4 py-3">
                                            <label for="counted-{{ $method->value }}" class="sr-only">Conferido em {{ $method->label() }}</label>
                                            <input id="counted-{{ $method->value }}" name="counted[{{ $method->value }}]" type="text" inputmode="decimal" autocomplete="off" required
                                                   value="{{ old($field) }}" @class(['input max-w-40', 'input-error' => $errors->has($field)])
                                                   @error($field) aria-invalid="true" aria-describedby="counted-{{ $method->value }}-error" @enderror>
                                            @error($field)
                                                <p id="counted-{{ $method->value }}-error" class="mt-1 text-sm text-red-700">{{ $message }}</p>
                                            @enderror
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                            <tfoot class="border-t border-slate-200 bg-slate-50 text-xs">
                                <tr>
                                    <td class="px-4 py-2 text-slate-600">Total</td>
                                    <td class="px-4 py-2 text-right font-semibold tabular-nums">{{ LocalizedDecimal::money($totalIn) }}</td>
                                    <td class="px-4 py-2 text-right font-semibold tabular-nums">{{ LocalizedDecimal::money($totalOut) }}</td>
                                    <td class="px-4 py-2 text-right font-semibold tabular-nums">{{ LocalizedDecimal::money($totalIn - $totalOut) }}</td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                    <x-form.textarea name="notes" label="Observações" rows="2" maxlength="500"
                                     hint="Explique sobras ou faltas. Não escreva dados de pacientes." />
                    <div class="flex justify-end">
                        <button type="submit" class="btn btn-primary">Fechar o caixa do dia</button>
                    </div>
                </form>
            @else
                @include('finance._cash_lines', ['lines' => collect($methods)->map(fn ($method) => (object) [
                    'payment_method' => $method,
                    'expected' => ($movement[$method->value]['in'] ?? 0) - ($movement[$method->value]['out'] ?? 0),
                    'counted' => null,
                ])])
            @endcan
        </section>
    @endif

    <section class="card mt-6 relative overflow-x-auto" aria-labelledby="pagamentos-do-dia">
        <h2 id="pagamentos-do-dia" class="px-4 pt-5 text-sm font-semibold tracking-wide text-slate-500 uppercase sm:px-6">{{ $canViewAll ? 'Pagamentos do dia' : 'Recebimentos do dia' }}</h2>
        @if ($listed->isEmpty())
            <p class="px-4 pt-4 pb-6 text-sm text-slate-600 sm:px-6">Nenhum pagamento neste dia.</p>
        @else
            <table class="table-stack mt-3 w-full min-w-2xl text-left text-sm">
                <thead class="border-y border-slate-200 bg-slate-50 text-xs tracking-wide text-slate-500 uppercase">
                    <tr>
                        <th scope="col" class="px-4 py-3 font-medium sm:px-6">Descrição</th>
                        <th scope="col" class="px-4 py-3 font-medium">Forma</th>
                        <th scope="col" class="px-4 py-3 text-right font-medium sm:px-6">Valor</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($listed as $installment)
                        @php $isIncome = $installment->entry->type === FinancialEntryType::Income; @endphp
                        <tr>
                            <td data-label="Descrição" class="px-4 py-3 sm:px-6">
                                {{ $installment->entry->description }}
                                @if ($installment->entry->patient)
                                    <span class="block text-xs text-slate-500">{{ $installment->entry->patient->name }}</span>
                                @endif
                            </td>
                            <td data-label="Forma" class="px-4 py-3 text-slate-600">{{ $installment->entry->payment_method->label() }}</td>
                            <td data-label="Valor" @class(['px-4 py-3 text-right font-medium whitespace-nowrap tabular-nums sm:px-6', 'text-emerald-700' => $isIncome, 'text-red-700' => ! $isIncome])>
                                {{ $isIncome ? '+' : '−' }} {{ LocalizedDecimal::money($installment->amount) }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </section>

    @if ($recent->isNotEmpty())
        <section class="card mt-6 p-6" aria-labelledby="fechamentos-anteriores">
            <h2 id="fechamentos-anteriores" class="text-sm font-semibold tracking-wide text-slate-500 uppercase">Fechamentos anteriores</h2>
            <ul class="mt-3 divide-y divide-slate-100 text-sm">
                @foreach ($recent as $previous)
                    @php $difference = $previous->difference(); @endphp
                    <li class="flex flex-wrap items-center justify-between gap-2 py-2">
                        <a href="{{ route('finance.cash.show', ['dia' => $previous->day->format('Y-m-d')]) }}" class="text-teal-800 hover:underline">{{ $previous->day->format('d/m/Y') }}</a>
                        <span @class(['tabular-nums', 'text-slate-600' => $difference == 0, 'text-red-700' => $difference < 0, 'text-amber-700' => $difference > 0])>
                            {{ $difference == 0 ? 'Sem diferença' : ($difference < 0 ? 'Falta de ' : 'Sobra de ').LocalizedDecimal::money(abs($difference)) }}
                        </span>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif
</x-finance.page>
