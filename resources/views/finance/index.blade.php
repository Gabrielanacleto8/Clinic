@php
    use App\Enums\FinancialEntryType;
    use App\Support\ClinicTime;
    use App\Support\LocalizedDecimal;

    $canManage = auth()->user()->can('create', App\Models\FinancialEntry::class);
    $monthLabel = fn ($date) => ucfirst($date->locale('pt_BR')->translatedFormat('F \d\e Y'));
    $largestRevenue = max(1, max($revenueByMonth), max($receivedByMonth));
    $today = ClinicTime::today();
    $periodLabel = $isWholeMonth
        ? $monthLabel($month)
        : ($start === $end ? Carbon\CarbonImmutable::parse($start)->format('d/m/Y') : Carbon\CarbonImmutable::parse($start)->format('d/m/Y').' a '.Carbon\CarbonImmutable::parse($end)->format('d/m/Y'));
    $periodWord = $isWholeMonth ? 'no mês' : 'no período';
    $filtered = $filters['tipo'] !== 'todos' || $filters['situacao'] !== 'todas' || $filters['paciente'] !== '';

    // Variação contra o período anterior, para o rodapé de cada card.
    $delta = function (float $now, float $before): ?string {
        if ($before == 0.0) {
            return $now == 0.0 ? null : 'sem base no período anterior';
        }

        $percent = ($now - $before) / abs($before) * 100;

        return ($percent >= 0 ? '+' : '−').number_format(abs($percent), 0, ',', '.').'% vs período anterior';
    };
    // Uma só ação de excluir por lançamento, na primeira parcela que aparece na página.
    $deleteShown = [];
@endphp
<x-layouts.app title="Financeiro">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold tracking-tight">Financeiro</h1>
            <p class="mt-1 text-sm text-slate-600">{{ $periodLabel }}</p>
        </div>
        @if ($canManage)
            <div class="flex flex-wrap items-center gap-2">
                <a href="{{ route('finance.entries.create', ['tipo' => FinancialEntryType::Expense->value]) }}" class="btn btn-secondary">Nova despesa</a>
                <a href="{{ route('finance.entries.create', ['tipo' => FinancialEntryType::Income->value]) }}" class="btn btn-primary">Nova receita</a>
            </div>
        @endif
    </div>

    <form method="GET" action="{{ route('finance.index') }}" class="mt-6 grid grid-cols-2 gap-2 sm:grid-cols-12" role="search">
        @include('stock._period_fields', ['span' => 'sm:col-span-2'])
        <div class="col-span-2 sm:col-span-3">
            <label for="paciente" class="sr-only">Paciente</label>
            <input id="paciente" name="paciente" type="search" value="{{ $filters['paciente'] }}" maxlength="100" class="input" placeholder="Paciente (nome ou CPF)">
        </div>
        <div class="sm:col-span-2">
            <label for="tipo" class="sr-only">Tipo</label>
            <select id="tipo" name="tipo" class="input">
                @foreach ($typeOptions as $value => $label)
                    <option value="{{ $value }}" @selected($filters['tipo'] === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div class="sm:col-span-2">
            <label for="situacao" class="sr-only">Situação</label>
            <select id="situacao" name="situacao" class="input">
                @foreach ($statusOptions as $value => $label)
                    <option value="{{ $value }}" @selected($filters['situacao'] === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <button type="submit" class="btn btn-secondary sm:col-span-1">Filtrar</button>
        <div class="col-span-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm sm:col-span-12">
            <a href="{{ route('finance.index', ['de' => $today, 'ate' => $today]) }}" class="text-teal-800 hover:underline">Hoje</a>
            <a href="{{ route('finance.index', ['mes' => substr($today, 0, 7)]) }}" class="text-teal-800 hover:underline">Este mês</a>
            @if ($filtered || ! $isWholeMonth || substr($start, 0, 7) !== substr($today, 0, 7))
                <a href="{{ route('finance.index') }}" class="text-slate-600 hover:underline">Limpar filtros</a>
            @endif
            {{-- Os mesmos filtros da tela, sem nome de paciente na planilha. --}}
            <a href="{{ route('finance.margin', ['de' => $start, 'ate' => $end]) }}" class="ml-auto text-teal-800 hover:underline">Margem por procedimento</a>
            <a href="{{ route('finance.export', array_filter($filters, filled(...))) }}" class="text-teal-800 hover:underline">Exportar planilha (CSV)</a>
        </div>
        @foreach (['mes', 'de', 'ate', 'tipo', 'situacao', 'paciente'] as $field)
            @error($field)
                <p class="col-span-2 text-sm text-red-700 sm:col-span-12">{{ $message }}</p>
            @enderror
        @endforeach
    </form>

    <dl class="mt-6 grid grid-cols-2 gap-4 lg:grid-cols-3">
        @foreach ([
            'Recebido' => ['received', "Receitas pagas $periodWord"],
            'Pendente' => ['receivable', "Receitas a vencer ou vencidas $periodWord"],
            'Despesas pagas' => ['expenses', "Despesas pagas $periodWord"],
            'Saldo' => ['balance', 'Recebido menos despesas pagas'],
            'Faturamento (lançado)' => ['revenue', "Receitas lançadas $periodWord, pagas ou não"],
            'Despesas a pagar' => ['payable', "Em aberto com vencimento $periodWord"],
        ] as $label => [$key, $caption])
            <div class="card px-4 py-3">
                <dt class="text-xs text-slate-500">{{ $label }}</dt>
                <dd @class(['mt-0.5 text-xl font-semibold tabular-nums', 'text-red-700' => $summary[$key] < 0])>{{ LocalizedDecimal::money($summary[$key]) }}</dd>
                <dd class="mt-0.5 text-xs text-slate-500">{{ $caption }}</dd>
                @if ($delta($summary[$key], $previous[$key]))
                    <dd class="mt-0.5 text-xs text-slate-400 tabular-nums">{{ $delta($summary[$key], $previous[$key]) }}</dd>
                @endif
            </div>
        @endforeach
    </dl>

    @if ($summary['overdue'] > 0)
        <p class="mt-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900" role="note">
            Há {{ LocalizedDecimal::money($summary['overdue']) }} em receitas vencidas e não recebidas, somando todos os meses.
            <a href="{{ route('finance.index', ['tipo' => 'receitas', 'situacao' => 'vencidas_todas']) }}" class="font-medium underline">Ver todas as vencidas</a>
        </p>
    @endif

    <section class="card mt-6 p-6" aria-labelledby="caixa">
        <h2 id="caixa" class="text-sm font-semibold tracking-wide text-slate-500 uppercase">Recebido {{ $periodWord }} por forma de pagamento</h2>
        @if ($receivedByMethod === [])
            <p class="mt-2 text-sm text-slate-600">Nenhum recebimento {{ $periodWord }}.</p>
        @else
            <dl class="mt-3 grid grid-cols-2 gap-x-6 gap-y-2 sm:grid-cols-3 lg:grid-cols-6">
                @foreach ($receivedByMethod as $method => $total)
                    <div>
                        <dt class="text-xs text-slate-500">{{ App\Enums\PaymentMethod::from($method)->label() }}</dt>
                        <dd class="text-sm font-semibold tabular-nums">{{ LocalizedDecimal::money($total) }}</dd>
                    </div>
                @endforeach
            </dl>
        @endif
    </section>

    <section class="card mt-6 relative overflow-x-auto" aria-labelledby="parcelas">
        <h2 id="parcelas" class="px-4 pt-5 text-sm font-semibold tracking-wide text-slate-500 uppercase sm:px-6">
            {{ $filters['situacao'] === 'vencidas_todas' ? 'Parcelas vencidas, todos os meses' : 'Parcelas do período' }}
        </h2>
        @if ($installments->isEmpty())
            <p class="px-4 pt-4 pb-6 text-sm text-slate-600 sm:px-6">{{ $filtered ? 'Nenhuma parcela com estes filtros.' : 'Nenhuma parcela neste período.' }}</p>
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
                            $firstOfEntry = ! isset($deleteShown[$entry->id]);
                            $deleteShown[$entry->id] = true;
                            $chargeValues = json_encode([
                                '_entry' => $entry->id,
                                'payment_method' => $entry->payment_method->value,
                                'installments' => $entry->installments_count,
                                'first_due_date' => $installment->number === 1 ? $installment->due_date->format('Y-m-d') : '',
                            ]);
                        @endphp
                        <tr>
                            <td data-label="Vencimento" class="px-4 py-3 whitespace-nowrap text-slate-600 tabular-nums sm:px-6">{{ $installment->due_date->format('d/m/Y') }}</td>
                            <td data-label="Descrição" class="px-4 py-3">
                                <p class="font-medium">{{ $entry->description }}</p>
                                <p class="mt-0.5 text-xs text-slate-500">
                                    {{ $entry->category->label() }} · {{ $entry->payment_method->label() }} · {{ $parcel }}
                                    @if ($entry->patient)
                                        · <a href="{{ route('patients.show', [$entry->patient, 'aba' => 'financeiro']) }}" class="text-teal-800 hover:underline">{{ $entry->patient->name }}</a>
                                    @endif
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
                                <span class="inline-flex flex-wrap justify-end gap-x-3 gap-y-1">
                                    @if ($installment->isPaid())
                                        @if ($canManage)
                                            <form method="POST" action="{{ route('finance.payments.destroy', $installment) }}" class="inline"
                                                  data-confirm="Desfazer o pagamento de {{ $entry->description }}? {{ $entry->payment_method->settlesAllInstallments($entry->type) && $entry->installments_count > 1 ? 'As '.$entry->installments_count.' parcelas do cartão voltam a ficar em aberto.' : 'A parcela volta a ficar em aberto.' }}">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="cursor-pointer font-medium text-slate-600 hover:underline">Desfazer</button>
                                            </form>
                                        @endif
                                    @elseif (auth()->user()->can('receive', $entry))
                                        @include('finance._receive_button')
                                    @endif
                                    @if ($firstOfEntry)
                                        @can('adjustCharge', $entry)
                                            <button type="button" class="cursor-pointer font-medium text-slate-600 hover:underline" data-dialog-open="charge-adjust"
                                                    data-dialog-action="{{ route('finance.entries.charge', $entry) }}"
                                                    data-dialog-values="{{ $chargeValues }}"
                                                    data-dialog-summary="{{ $entry->description }} · {{ LocalizedDecimal::money($entry->total()) }}">Ajustar cobrança</button>
                                        @endcan
                                        @can('update', $entry)
                                            <a href="{{ route('finance.entries.edit', $entry) }}" class="font-medium text-slate-600 hover:underline">Editar</a>
                                        @endcan
                                        @if (! $isIncome && $canManage)
                                            <a href="{{ route('finance.entries.create', ['repetir' => $entry->id]) }}" class="font-medium text-slate-600 hover:underline" title="Lançar de novo, com os mesmos dados, na data de hoje">Repetir</a>
                                        @endif
                                        @can('delete', $entry)
                                            <form method="POST" action="{{ route('finance.entries.destroy', $entry) }}" class="inline"
                                                  data-confirm="Excluir o lançamento {{ $entry->description }} ({{ LocalizedDecimal::money($entry->total()) }}, {{ $entry->installments_count === 1 ? 'à vista' : $entry->installments_count.' parcelas' }})? Todas as parcelas somem.">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="cursor-pointer font-medium text-red-700 hover:underline">Excluir</button>
                                            </form>
                                        @endcan
                                    @endif
                                </span>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot class="border-t border-slate-200 bg-slate-50 text-xs">
                    <tr>
                        <td colspan="2" class="px-4 py-2 text-slate-600 sm:px-6">Soma das {{ $installments->total() }} parcelas com estes filtros</td>
                        <td class="px-4 py-2 text-right font-semibold tabular-nums">{{ LocalizedDecimal::money($listingTotal) }}</td>
                        <td colspan="2"></td>
                    </tr>
                </tfoot>
            </table>
        @endif
    </section>

    <div class="mt-4">
        {{ $installments->links() }}
    </div>

    <section class="card mt-6 p-6" aria-labelledby="faturamento">
        <h2 id="faturamento" class="text-sm font-semibold tracking-wide text-slate-500 uppercase">Faturamento por período</h2>
        <p class="mt-1 text-xs text-slate-500">Lançado: receitas pela data do lançamento. Recebido: o que entrou no mês, pela data do pagamento. A variação compara o lançado com o mesmo mês do ano anterior.</p>
        <p class="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-xs text-slate-600" aria-hidden="true">
            <span class="flex items-center gap-1.5"><span class="size-2 rounded-full bg-teal-600"></span>Lançado</span>
            <span class="flex items-center gap-1.5"><span class="size-2 rounded-full bg-emerald-400"></span>Recebido</span>
        </p>
        <ul class="mt-3 divide-y divide-slate-100 text-sm">
            @foreach ($revenueByMonth as $period => $total)
                @php
                    $periodDate = Carbon\CarbonImmutable::createFromFormat('!Y-m', $period);
                    $received = $receivedByMonth[$period] ?? 0;
                    $yearBefore = $revenueYearBefore[$period] ?? 0;
                    $yearChange = $yearBefore > 0 ? ($total - $yearBefore) / $yearBefore * 100 : null;
                @endphp
                <li class="grid grid-cols-1 gap-x-4 gap-y-1.5 py-2.5 sm:grid-cols-[10rem_minmax(0,1fr)_14rem] sm:items-center">
                    <a href="{{ route('finance.index', ['mes' => $period]) }}" class="text-teal-800 hover:underline">{{ $monthLabel($periodDate) }}</a>
                    <div class="space-y-1">
                        <progress class="bar text-teal-600" max="{{ $largestRevenue }}" value="{{ $total }}" aria-label="Lançado em {{ $monthLabel($periodDate) }}">{{ LocalizedDecimal::money($total) }}</progress>
                        <progress class="bar text-emerald-400" max="{{ $largestRevenue }}" value="{{ $received }}" aria-label="Recebido em {{ $monthLabel($periodDate) }}">{{ LocalizedDecimal::money($received) }}</progress>
                    </div>
                    <p class="text-xs text-slate-600 tabular-nums sm:text-right">
                        <span class="font-medium text-slate-900">{{ LocalizedDecimal::money($total) }}</span> lançado
                        · {{ LocalizedDecimal::money($received) }} recebido
                        @if ($yearChange !== null)
                            <span @class(['block', 'text-emerald-700' => $yearChange >= 0, 'text-red-700' => $yearChange < 0])>
                                {{ $yearChange >= 0 ? '+' : '−' }}{{ number_format(abs($yearChange), 0, ',', '.') }}% vs {{ $periodDate->subYear()->format('m/Y') }}
                            </span>
                        @endif
                    </p>
                </li>
            @endforeach
        </ul>
    </section>

    @if (auth()->user()->hasPermission(App\Enums\Permission::FinanceReceive))
        @include('finance._payment_dialog')
    @endif

    @if ($canManage)
        <x-dialog id="charge-adjust" title="Ajustar cobrança do atendimento">
            {{-- O destino vem do botão que abre (data-dialog-action); ao voltar com erro, do lançamento guardado em _entry. --}}
            <form method="POST" action="{{ old('_dialog') === 'charge-adjust' && is_numeric(old('_entry')) ? route('finance.entries.charge', (int) old('_entry')) : route('finance.index') }}" class="space-y-4" novalidate>
                @csrf
                @method('PUT')
                <input type="hidden" name="_dialog" value="charge-adjust">
                <input type="hidden" name="_entry" value="{{ old('_entry') }}">
                <p class="text-sm text-slate-700" data-dialog-summary></p>
                <p class="text-xs text-slate-500">O valor não muda e o registro do atendimento fica como está. As parcelas são refeitas: só vale enquanto nada foi recebido.</p>
                @error('installments')
                    <p class="text-sm text-red-700">{{ $message }}</p>
                @enderror
                <x-form.select scope="charge-adjust" name="payment_method" label="Forma de pagamento" :options="$paymentMethods" required placeholder="Selecione" />
                <x-form.select scope="charge-adjust" name="installments" label="Parcelas" required
                               :options="collect(range(1, App\Support\InstallmentPlan::MAX_INSTALLMENTS))->mapWithKeys(fn ($n) => [$n => $n === 1 ? 'À vista' : $n.'x'])->all()" />
                <x-form.input scope="charge-adjust" name="first_due_date" label="Primeiro vencimento" type="date" required :value="$today" />
                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" class="btn btn-secondary" data-dialog-close>Cancelar</button>
                    <button type="submit" class="btn btn-primary">Ajustar</button>
                </div>
            </form>
        </x-dialog>
    @endif
</x-layouts.app>
