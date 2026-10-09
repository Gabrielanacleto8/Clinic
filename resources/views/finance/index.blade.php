@php
    use App\Enums\FinancialEntryType;
    use App\Support\ClinicTime;
    use App\Support\LocalizedDecimal;

    $canManage = auth()->user()->can('create', App\Models\FinancialEntry::class);
    $monthLabel = fn ($date) => ucfirst($date->locale('pt_BR')->translatedFormat('F \d\e Y'));
    $largestRevenue = max(1, max($revenueByMonth));
@endphp
<x-layouts.app title="Financeiro">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold tracking-tight">Financeiro</h1>
            <p class="mt-1 text-sm text-slate-600">{{ $monthLabel($month) }}</p>
        </div>
        @if ($canManage)
            <div class="flex flex-wrap items-center gap-2">
                <a href="{{ route('finance.entries.create', ['tipo' => FinancialEntryType::Expense->value]) }}" class="btn btn-secondary">Nova despesa</a>
                <a href="{{ route('finance.entries.create', ['tipo' => FinancialEntryType::Income->value]) }}" class="btn btn-primary">Nova receita</a>
            </div>
        @endif
    </div>

    <form method="GET" action="{{ route('finance.index') }}" class="mt-6 grid grid-cols-1 gap-2 sm:grid-cols-12">
        <div class="sm:col-span-3">
            <label for="mes" class="sr-only">Mês</label>
            <input id="mes" name="mes" type="month" value="{{ $filters['mes'] }}" required @class(['input', 'input-error' => $errors->has('mes')])>
        </div>
        <div class="sm:col-span-4">
            <label for="tipo" class="sr-only">Tipo</label>
            <select id="tipo" name="tipo" class="input">
                @foreach ($typeOptions as $value => $label)
                    <option value="{{ $value }}" @selected($filters['tipo'] === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div class="sm:col-span-3">
            <label for="situacao" class="sr-only">Situação</label>
            <select id="situacao" name="situacao" class="input">
                @foreach ($statusOptions as $value => $label)
                    <option value="{{ $value }}" @selected($filters['situacao'] === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <button type="submit" class="btn btn-secondary sm:col-span-2">Filtrar</button>
        @foreach (['mes', 'tipo', 'situacao'] as $field)
            @error($field)
                <p class="text-sm text-red-700 sm:col-span-12">{{ $message }}</p>
            @enderror
        @endforeach
    </form>

    <dl class="mt-6 grid grid-cols-2 gap-4 lg:grid-cols-3">
        @foreach ([
            'Total recebido' => [$summary['received'], 'Receitas pagas no mês'],
            'Total pendente' => [$summary['receivable'], 'Receitas a vencer ou vencidas no mês'],
            'Total de despesas' => [$summary['expenses'], 'Despesas pagas no mês'],
            'Saldo' => [$summary['balance'], 'Recebido menos despesas pagas'],
            'Faturamento' => [$summary['revenue'], 'Receitas lançadas no mês'],
            'Despesas a pagar' => [$summary['payable'], 'Em aberto com vencimento no mês'],
        ] as $label => [$value, $caption])
            <div class="card px-4 py-3">
                <dt class="text-xs text-slate-500">{{ $label }}</dt>
                <dd @class(['mt-0.5 text-xl font-semibold tabular-nums', 'text-red-700' => $value < 0])>{{ LocalizedDecimal::money($value) }}</dd>
                <dd class="mt-0.5 text-xs text-slate-500">{{ $caption }}</dd>
            </div>
        @endforeach
    </dl>

    @if ($summary['overdue'] > 0)
        <p class="mt-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900" role="note">
            Há {{ LocalizedDecimal::money($summary['overdue']) }} em receitas vencidas e não recebidas, somando todos os meses.
            <a href="{{ route('finance.index', ['mes' => $filters['mes'], 'tipo' => 'receitas', 'situacao' => 'vencidas']) }}" class="font-medium underline">Ver as deste mês</a>
        </p>
    @endif

    <section class="card mt-6 relative overflow-x-auto" aria-labelledby="parcelas">
        <h2 id="parcelas" class="px-4 pt-5 text-sm font-semibold tracking-wide text-slate-500 uppercase sm:px-6">Lançamentos do mês</h2>
        @if ($installments->isEmpty())
            <p class="px-4 pt-4 pb-6 text-sm text-slate-600 sm:px-6">Nenhum lançamento para este filtro.</p>
        @else
            <table class="table-stack mt-3 w-full min-w-3xl text-left text-sm">
                <thead class="border-y border-slate-200 bg-slate-50 text-xs tracking-wide text-slate-500 uppercase">
                    <tr>
                        <th scope="col" class="px-4 py-3 font-medium sm:px-6">Vencimento</th>
                        <th scope="col" class="px-4 py-3 font-medium">Descrição</th>
                        <th scope="col" class="px-4 py-3 text-right font-medium">Valor</th>
                        <th scope="col" class="px-4 py-3 font-medium">Situação</th>
                        <th scope="col" class="px-4 py-3 sm:px-6"><span class="sr-only">Ações</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($installments as $installment)
                        @php
                            $entry = $installment->entry;
                            $isIncome = $entry->type === FinancialEntryType::Income;
                            $badge = $installment->statusBadge();
                            $parcel = $entry->installments_count > 1 ? 'Parcela '.$installment->number.'/'.$entry->installments_count : 'À vista';
                        @endphp
                        <tr>
                            <td data-label="Vencimento" class="px-4 py-3 whitespace-nowrap text-slate-600 tabular-nums sm:px-6">{{ $installment->due_date->format('d/m/Y') }}</td>
                            <td data-label="Descrição" class="px-4 py-3">
                                <p class="font-medium">{{ $entry->description }}</p>
                                <p class="mt-0.5 text-xs text-slate-500">
                                    {{ $entry->category->label() }} · {{ $entry->payment_method->label() }} · {{ $parcel }}
                                    @if ($entry->patient) · {{ $entry->patient->name }} @endif
                                </p>
                            </td>
                            <td data-label="Valor" @class(['px-4 py-3 text-right font-medium whitespace-nowrap tabular-nums', 'text-emerald-700' => $isIncome, 'text-red-700' => ! $isIncome])>
                                {{ $isIncome ? '+' : '−' }} {{ LocalizedDecimal::money($installment->amount) }}
                            </td>
                            <td data-label="Situação" class="px-4 py-3 whitespace-nowrap">
                                <span class="badge {{ $badge['classes'] }}">{{ $badge['label'] }}</span>
                                @if ($installment->isPaid())
                                    <span class="ml-1 text-xs text-slate-500">em {{ $installment->paid_at->format('d/m/Y') }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right whitespace-nowrap sm:px-6">
                                @if ($canManage)
                                    @if ($installment->isPaid())
                                        <form method="POST" action="{{ route('finance.payments.destroy', $installment) }}" class="inline"
                                              data-confirm="Desfazer o pagamento de {{ $entry->description }}? A parcela volta a ficar em aberto.">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="cursor-pointer font-medium text-slate-600 hover:underline">Desfazer</button>
                                        </form>
                                    @else
                                        <button type="button" class="cursor-pointer font-medium text-teal-800 hover:underline" data-dialog-open="installment-payment"
                                                data-dialog-values='@json(['installment_id' => $installment->id])'
                                                data-dialog-summary="{{ $entry->description }} · {{ $parcel }} · {{ LocalizedDecimal::money($installment->amount) }}">
                                            {{ $isIncome ? 'Receber' : 'Pagar' }}
                                        </button>
                                    @endif
                                    @can('delete', $entry)
                                        <form method="POST" action="{{ route('finance.entries.destroy', $entry) }}" class="ml-3 inline"
                                              data-confirm="Excluir o lançamento {{ $entry->description }} com todas as suas parcelas?">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="cursor-pointer font-medium text-red-700 hover:underline">Excluir</button>
                                        </form>
                                    @endcan
                                @endif
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

    <section class="card mt-6 p-6" aria-labelledby="faturamento">
        <h2 id="faturamento" class="text-sm font-semibold tracking-wide text-slate-500 uppercase">Faturamento por período</h2>
        <p class="mt-1 text-xs text-slate-500">Receitas lançadas em cada mês, pela data do lançamento.</p>
        <table class="mt-4 w-full text-sm">
            <tbody class="divide-y divide-slate-100">
                @foreach ($revenueByMonth as $period => $total)
                    @php $periodDate = Carbon\CarbonImmutable::createFromFormat('!Y-m', $period); @endphp
                    <tr>
                        <th scope="row" class="w-40 py-2 pr-4 text-left font-normal whitespace-nowrap">
                            <a href="{{ route('finance.index', ['mes' => $period]) }}" class="text-teal-800 hover:underline">{{ $monthLabel($periodDate) }}</a>
                        </th>
                        <td class="py-2">
                            <progress class="block h-2 w-full accent-teal-700" max="{{ $largestRevenue }}" value="{{ $total }}">{{ LocalizedDecimal::money($total) }}</progress>
                        </td>
                        <td class="w-36 py-2 pl-4 text-right whitespace-nowrap tabular-nums">{{ LocalizedDecimal::money($total) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </section>

    @if ($canManage)
        <x-dialog id="installment-payment" title="Registrar pagamento">
            <form method="POST" action="{{ route('finance.payments.store') }}" class="space-y-4" novalidate>
                @csrf
                <input type="hidden" name="_dialog" value="installment-payment">
                <input type="hidden" name="installment_id" value="{{ old('installment_id') }}">

                <p class="text-sm text-slate-700" data-dialog-summary></p>
                @error('installment_id')
                    <p class="text-sm text-red-700">{{ $message }}</p>
                @enderror

                <x-form.input scope="installment-payment" name="paid_at" label="Data do pagamento" type="date" required
                              :value="ClinicTime::today()" :max="ClinicTime::today()" />

                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" class="btn btn-secondary" data-dialog-close>Cancelar</button>
                    <button type="submit" class="btn btn-primary">Confirmar</button>
                </div>
            </form>
        </x-dialog>
    @endif
</x-layouts.app>
