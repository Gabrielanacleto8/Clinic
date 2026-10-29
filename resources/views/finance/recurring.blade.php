@php
    use App\Enums\CostBehavior;
    use App\Models\FinancialEntry;
    use App\Models\RecurringExpense;
    use App\Support\LocalizedDecimal;

    $canManage = auth()->user()->can('manage', FinancialEntry::class);
    $filters = ['de' => $start, 'ate' => $end];
    $fixed = collect($byCategory)->where('behavior', CostBehavior::Fixed)->sum('total');
    $variable = collect($byCategory)->where('behavior', CostBehavior::Variable)->sum('total') + $cardFees;
    $refunds = collect($byCategory)->where('behavior', null)->sum('total');
    $format = fn (string $date) => Carbon\CarbonImmutable::createFromFormat('!Y-m-d', $date)->format('d/m/Y');
    $monthly = $expenses->where('is_active', true)->sum(fn ($expense) => (float) $expense->amount);
@endphp
<x-finance.page active="fixas" title="Despesas fixas e variáveis" :subtitle="$format($start).' a '.$format($end)">
    @if ($canManage)
        <x-slot:actions>
            <button type="button" class="btn btn-primary" data-dialog-open="recurring-expense-create">Nova despesa recorrente</button>
        </x-slot:actions>
    @endif

    <form method="GET" action="{{ route('finance.recurring.index') }}" class="mt-4 grid grid-cols-2 gap-2 sm:grid-cols-12" role="search">
        @include('stock._period_fields', ['span' => 'sm:col-span-4'])
        <button type="submit" class="btn btn-secondary col-span-2 sm:col-span-2">Filtrar</button>
    </form>

    <dl class="mt-6 grid grid-cols-2 gap-3 lg:grid-cols-4">
        <div class="card px-4 py-3">
            <dt class="text-xs text-slate-500">Despesas fixas</dt>
            <dd class="mt-0.5 text-lg font-semibold tabular-nums">{{ LocalizedDecimal::money($fixed) }}</dd>
            <dd class="text-xs text-slate-500">Lançadas no período</dd>
        </div>
        <div class="card px-4 py-3">
            <dt class="text-xs text-slate-500">Despesas variáveis</dt>
            <dd class="mt-0.5 text-lg font-semibold tabular-nums">{{ LocalizedDecimal::money($variable) }}</dd>
            <dd class="text-xs text-slate-500">Inclui {{ LocalizedDecimal::money($cardFees) }} de taxas da conciliação</dd>
        </div>
        <div class="card px-4 py-3">
            <dt class="text-xs text-slate-500">Estornos a pacientes</dt>
            <dd class="mt-0.5 text-lg font-semibold tabular-nums">{{ LocalizedDecimal::money($refunds) }}</dd>
            <dd class="text-xs text-slate-500">Devolução de receita, fora da conta</dd>
        </div>
        <div class="card px-4 py-3">
            <dt class="text-xs text-slate-500">Recorrentes por mês</dt>
            <dd class="mt-0.5 text-lg font-semibold tabular-nums">{{ LocalizedDecimal::money($monthly) }}</dd>
            <dd class="text-xs text-slate-500">Soma das recorrências ativas</dd>
        </div>
    </dl>

    @if ($byCategory !== [] || $cardFees > 0)
        <section class="card mt-6 relative overflow-x-auto" aria-labelledby="por-categoria">
            <h2 id="por-categoria" class="px-4 pt-5 text-sm font-semibold tracking-wide text-slate-500 uppercase sm:px-6">Despesas do período por categoria</h2>
            <table class="table-stack mt-3 w-full min-w-xl text-left text-sm">
                <thead class="border-y border-slate-200 bg-slate-50 text-xs tracking-wide text-slate-500 uppercase">
                    <tr>
                        <th scope="col" class="px-4 py-3 font-medium sm:px-6">Categoria</th>
                        <th scope="col" class="px-4 py-3 font-medium">Tipo</th>
                        <th scope="col" class="px-4 py-3 text-right font-medium sm:px-6">Total</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($byCategory as $row)
                        <tr>
                            <th scope="row" class="px-4 py-3 font-normal sm:px-6">{{ $row['category']->label() }}</th>
                            <td data-label="Tipo" class="px-4 py-3 text-slate-600">{{ $row['behavior']?->label() ?? 'Estorno' }}</td>
                            <td data-label="Total" class="px-4 py-3 text-right tabular-nums sm:px-6">{{ LocalizedDecimal::money($row['total']) }}</td>
                        </tr>
                    @endforeach
                    @if ($cardFees > 0)
                        <tr>
                            <th scope="row" class="px-4 py-3 font-normal sm:px-6">Taxas descontadas na conciliação</th>
                            <td data-label="Tipo" class="px-4 py-3 text-slate-600">{{ CostBehavior::Variable->label() }}</td>
                            <td data-label="Total" class="px-4 py-3 text-right tabular-nums sm:px-6">{{ LocalizedDecimal::money($cardFees) }}</td>
                        </tr>
                    @endif
                </tbody>
            </table>
        </section>
    @endif

    <section class="card mt-6 relative overflow-x-auto" aria-labelledby="recorrentes">
        <h2 id="recorrentes" class="px-4 pt-5 text-sm font-semibold tracking-wide text-slate-500 uppercase sm:px-6">Despesas recorrentes</h2>
        <p class="px-4 pt-1 text-xs text-slate-500 sm:px-6">Lançadas sozinhas, em aberto, no começo de cada mês. Pague em Contas a pagar.</p>
        @if ($expenses->isEmpty())
            <p class="px-4 pt-4 pb-6 text-sm text-slate-600 sm:px-6">Nenhuma despesa recorrente cadastrada.</p>
        @else
            <table class="table-stack mt-3 w-full min-w-2xl text-left text-sm">
                <thead class="border-y border-slate-200 bg-slate-50 text-xs tracking-wide text-slate-500 uppercase">
                    <tr>
                        <th scope="col" class="px-4 py-3 font-medium sm:px-6">Descrição</th>
                        <th scope="col" class="px-4 py-3 text-right font-medium">Valor</th>
                        <th scope="col" class="px-4 py-3 font-medium">Vencimento</th>
                        <th scope="col" class="px-4 py-3 font-medium">Situação</th>
                        <th scope="col" class="px-4 py-3 sm:px-6"><span class="sr-only">Ações</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($expenses as $expense)
                        <tr @class(['text-slate-400' => ! $expense->is_active])>
                            <td data-label="Descrição" class="px-4 py-3 sm:px-6">
                                <p class="font-medium">{{ $expense->description }}</p>
                                <p class="text-xs text-slate-500">{{ $expense->category->label() }} · {{ $expense->category->costBehavior()?->label() }} · {{ $expense->payment_method->label() }}</p>
                            </td>
                            <td data-label="Valor" class="px-4 py-3 text-right tabular-nums">{{ LocalizedDecimal::money($expense->amount) }}</td>
                            <td data-label="Vencimento" class="px-4 py-3 whitespace-nowrap">
                                Todo dia {{ $expense->due_day }}
                                <span class="block text-xs text-slate-500">desde {{ $expense->starts_on->format('m/Y') }}{{ $expense->ends_on ? ' até '.$expense->ends_on->format('m/Y') : '' }}</span>
                            </td>
                            <td data-label="Situação" class="px-4 py-3">
                                <span @class(['badge', 'bg-emerald-100 text-emerald-800' => $expense->is_active, 'bg-slate-200 text-slate-700' => ! $expense->is_active])>{{ $expense->is_active ? 'Ativa' : 'Encerrada' }}</span>
                            </td>
                            <td class="px-4 py-3 text-right sm:px-6">
                                @if ($canManage)
                                    <button type="button" class="cursor-pointer font-medium text-teal-800 hover:underline" data-dialog-open="recurring-expense-edit"
                                            data-dialog-action="{{ route('finance.recurring.update', $expense) }}"
                                            data-dialog-summary="{{ $expense->description }}"
                                            data-dialog-values="{{ json_encode([
                                                '_recurring' => $expense->id,
                                                'description' => $expense->description,
                                                'category' => $expense->category->value,
                                                'amount' => LocalizedDecimal::moneyInput($expense->amount),
                                                'payment_method' => $expense->payment_method->value,
                                                'due_day' => $expense->due_day,
                                                'starts_on' => $expense->starts_on->format('Y-m-d'),
                                                'ends_on' => $expense->ends_on?->format('Y-m-d'),
                                                'is_active' => $expense->is_active,
                                            ]) }}">Editar</button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </section>

    @if ($canManage)
        <x-dialog id="recurring-expense-create" title="Nova despesa recorrente" size="lg">
            <form method="POST" action="{{ route('finance.recurring.store') }}" class="space-y-4" novalidate>
                @csrf
                <input type="hidden" name="_dialog" value="recurring-expense-create">
                @include('finance._recurring_fields', ['scope' => 'recurring-expense-create', 'expense' => new RecurringExpense])
                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" class="btn btn-secondary" data-dialog-close>Cancelar</button>
                    <button type="submit" class="btn btn-primary">Cadastrar</button>
                </div>
            </form>
        </x-dialog>

        <x-dialog id="recurring-expense-edit" title="Editar despesa recorrente" size="lg">
            <form method="POST" action="{{ $editing ? route('finance.recurring.update', $editing) : '' }}" class="space-y-4" novalidate>
                @csrf
                @method('PUT')
                <input type="hidden" name="_dialog" value="recurring-expense-edit">
                <input type="hidden" name="_recurring" value="{{ $editing?->id }}">
                <p class="text-sm font-medium text-slate-700" data-dialog-summary>{{ $editing?->description }}</p>
                @include('finance._recurring_fields', ['scope' => 'recurring-expense-edit', 'expense' => $editing ?? new RecurringExpense])
                <p class="text-xs text-slate-500">A mudança vale para os próximos meses. Despesas já lançadas se editam em Contas a pagar.</p>
                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" class="btn btn-secondary" data-dialog-close>Cancelar</button>
                    <button type="submit" class="btn btn-primary">Salvar</button>
                </div>
            </form>
        </x-dialog>
    @endif
</x-finance.page>
