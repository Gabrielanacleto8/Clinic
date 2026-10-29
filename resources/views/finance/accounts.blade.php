@php
    use App\Enums\Permission;
    use App\Support\LocalizedDecimal;

    $route = $isIncome ? 'finance.receivable' : 'finance.payable';
    $grand = array_sum(array_column($totals, 'amount'));
    $bucketClass = ['vencidas' => 'text-red-700', 'hoje' => 'text-amber-700'];
@endphp
<x-finance.page :active="$isIncome ? 'receber' : 'pagar'" :title="$isIncome ? 'Contas a receber' : 'Contas a pagar'"
                :subtitle="LocalizedDecimal::money($grand).' em aberto, somando todos os meses'">
    @if (! $isIncome && auth()->user()->can('create', App\Models\FinancialEntry::class))
        <x-slot:actions>
            <a href="{{ route('finance.entries.create', ['tipo' => 'expense']) }}" class="btn btn-primary">Nova despesa</a>
        </x-slot:actions>
    @endif

    <dl class="mt-6 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
        @foreach ($buckets as $key => $label)
            @php $current = $filters['faixa'] === $key; @endphp
            <div @class(['card px-4 py-3', 'ring-2 ring-teal-600' => $current])>
                <dt class="text-xs text-slate-500">
                    <a href="{{ route($route, array_filter(['faixa' => $current ? null : $key, 'q' => $filters['q']])) }}" class="hover:underline" @if ($current) aria-current="true" @endif>{{ $label }}</a>
                </dt>
                <dd @class(['mt-0.5 text-lg font-semibold tabular-nums', $bucketClass[$key] ?? '' => $totals[$key]['amount'] > 0])>{{ LocalizedDecimal::money($totals[$key]['amount']) }}</dd>
                <dd class="text-xs text-slate-500">{{ App\Support\Plural::of($totals[$key]['count'], 'parcela', 'parcelas') }}</dd>
            </div>
        @endforeach
    </dl>

    <form method="GET" action="{{ route($route) }}" class="mt-4 grid grid-cols-1 gap-2 sm:grid-cols-12" role="search">
        @if ($filters['faixa'])
            <input type="hidden" name="faixa" value="{{ $filters['faixa'] }}">
        @endif
        <div class="sm:col-span-8">
            <label for="q" class="sr-only">Buscar</label>
            <input id="q" name="q" type="search" value="{{ $filters['q'] }}" maxlength="100" class="input"
                   placeholder="{{ $isIncome ? 'Descrição ou paciente (nome, CPF completo ou telefone)' : 'Descrição' }}">
        </div>
        <button type="submit" class="btn btn-secondary sm:col-span-2">Buscar</button>
        @if ($filters['faixa'] || $filters['q'] !== '')
            <a href="{{ route($route) }}" class="self-center text-sm text-slate-600 hover:underline sm:col-span-2">Limpar filtros</a>
        @endif
    </form>

    <section class="card mt-4 relative overflow-x-auto" aria-labelledby="parcelas-abertas">
        <h2 id="parcelas-abertas" class="sr-only">Parcelas em aberto</h2>
        @if ($installments->isEmpty())
            <p class="px-4 py-10 text-center text-sm text-slate-500">Nenhuma parcela em aberto{{ $filters['faixa'] || $filters['q'] !== '' ? ' com estes filtros' : '' }}.</p>
        @else
            <table class="table-stack w-full min-w-2xl text-left text-sm">
                <thead class="border-b border-slate-200 bg-slate-50 text-xs tracking-wide text-slate-500 uppercase">
                    <tr>
                        <th scope="col" class="px-4 py-3 font-medium sm:px-6">Vencimento</th>
                        <th scope="col" class="px-4 py-3 font-medium">Descrição</th>
                        <th scope="col" class="px-4 py-3 text-right font-medium">Valor</th>
                        <th scope="col" class="px-4 py-3 sm:px-6"><span class="sr-only">Ações</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($installments as $installment)
                        @php
                            $entry = $installment->entry;
                            $badge = $installment->statusBadge();
                        @endphp
                        <tr>
                            <td data-label="Vencimento" class="px-4 py-3 whitespace-nowrap tabular-nums sm:px-6">
                                {{ $installment->due_date->format('d/m/Y') }}
                                <span class="badge {{ $badge['classes'] }} ml-1">{{ $badge['label'] }}</span>
                            </td>
                            <td data-label="Descrição" class="px-4 py-3">
                                <p class="font-medium">{{ $entry->description }}</p>
                                <p class="mt-0.5 text-xs text-slate-500">
                                    {{ $entry->category->label() }} · {{ $entry->payment_method->label() }}
                                    · {{ $entry->installments_count > 1 ? 'Parcela '.$installment->number.'/'.$entry->installments_count : 'À vista' }}
                                    @if ($entry->patient)
                                        · <a href="{{ route('patients.show', [$entry->patient, 'aba' => 'financeiro']) }}" class="text-teal-800 hover:underline">{{ $entry->patient->name }}</a>
                                    @endif
                                </p>
                            </td>
                            <td data-label="Valor" class="px-4 py-3 text-right font-medium whitespace-nowrap tabular-nums">{{ LocalizedDecimal::money($installment->amount) }}</td>
                            <td class="px-4 py-3 text-right whitespace-nowrap sm:px-6">
                                @can('receive', $entry)
                                    @include('finance._receive_button')
                                @endcan
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </section>

    <div class="mt-4">
        {{ $installments->links() }}
    </div>

    @if (auth()->user()->hasPermission(Permission::FinanceReceive))
        @include('finance._payment_dialog')
    @endif
</x-finance.page>
