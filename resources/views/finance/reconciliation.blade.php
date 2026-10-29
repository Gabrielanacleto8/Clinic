@php
    use App\Support\LocalizedDecimal;

    $pending = (float) $summary->total - (float) $summary->reconciled;
    $selectable = $installments->filter(fn ($installment) => ! $installment->isReconciled());
@endphp
<x-finance.page active="conciliacao" title="Conciliação" subtitle="Confira os recebimentos de cartão, Pix, boleto e transferência no extrato e registre a taxa cobrada.">
    <form method="GET" action="{{ route('finance.reconciliation') }}" class="mt-4 grid grid-cols-2 gap-2 sm:grid-cols-12" role="search">
        @include('stock._period_fields', ['span' => 'sm:col-span-3'])
        <div class="sm:col-span-2">
            <label for="forma" class="sr-only">Forma</label>
            <select id="forma" name="forma" class="input">
                <option value="">Todas as formas</option>
                @foreach ($methods as $value => $label)
                    <option value="{{ $value }}" @selected($filters['forma'] === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div class="sm:col-span-2">
            <label for="situacao" class="sr-only">Situação</label>
            <select id="situacao" name="situacao" class="input">
                @foreach ($statuses as $value => $label)
                    <option value="{{ $value }}" @selected($filters['situacao'] === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <button type="submit" class="btn btn-secondary col-span-2 sm:col-span-2">Filtrar</button>
        @foreach (['de', 'ate', 'forma', 'situacao'] as $field)
            @error($field)
                <p class="col-span-2 text-sm text-red-700 sm:col-span-12">{{ $message }}</p>
            @enderror
        @endforeach
    </form>

    <dl class="mt-6 grid grid-cols-2 gap-3 lg:grid-cols-4">
        @foreach ([
            'Recebido no período' => $summary->total,
            'Conferido' => $summary->reconciled,
            'Pendente de conferência' => $pending,
            'Taxas cobradas' => $summary->fees,
        ] as $label => $value)
            <div class="card px-4 py-3">
                <dt class="text-xs text-slate-500">{{ $label }}</dt>
                <dd @class(['mt-0.5 text-lg font-semibold tabular-nums', 'text-amber-700' => $label === 'Pendente de conferência' && $pending > 0])>{{ LocalizedDecimal::money($value) }}</dd>
            </div>
        @endforeach
    </dl>

    <form method="POST" action="{{ route('finance.reconciliation.store') }}" id="reconcile-form" class="mt-6 space-y-4" novalidate>
        @csrf
        <section class="card relative overflow-x-auto" aria-labelledby="recebimentos">
            <h2 id="recebimentos" class="px-4 pt-5 text-sm font-semibold tracking-wide text-slate-500 uppercase sm:px-6">Recebimentos</h2>
            @error('installments')
                <p class="px-4 pt-2 text-sm text-red-700 sm:px-6">{{ $message }}</p>
            @enderror
            @if ($installments->isEmpty())
                <p class="px-4 pt-4 pb-6 text-sm text-slate-600 sm:px-6">Nenhum recebimento {{ $filters['situacao'] === 'conferidos' ? 'conferido' : ($filters['situacao'] === 'pendentes' ? 'pendente' : '') }} neste período.</p>
            @else
                <table class="table-stack mt-3 w-full min-w-3xl text-left text-sm">
                    <thead class="border-y border-slate-200 bg-slate-50 text-xs tracking-wide text-slate-500 uppercase">
                        <tr>
                            <th scope="col" class="px-4 py-3 sm:px-6"><span class="sr-only">Conferir</span></th>
                            <th scope="col" class="px-4 py-3 font-medium">Recebido em</th>
                            <th scope="col" class="px-4 py-3 font-medium">Descrição</th>
                            <th scope="col" class="px-4 py-3 text-right font-medium">Valor</th>
                            <th scope="col" class="px-4 py-3 font-medium">Taxa (R$)</th>
                            <th scope="col" class="px-4 py-3 font-medium sm:px-6">Situação</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($installments as $installment)
                            @php $entry = $installment->entry; @endphp
                            <tr>
                                <td class="px-4 py-3 sm:px-6">
                                    @unless ($installment->isReconciled())
                                        <input type="checkbox" name="installments[]" value="{{ $installment->id }}" class="size-4 cursor-pointer accent-brand max-md:size-6"
                                               aria-label="Conferir {{ $entry->description }}, {{ LocalizedDecimal::money($installment->amount) }}"
                                               @checked(in_array((string) $installment->id, array_map('strval', (array) old('installments', [])), true))>
                                    @endunless
                                </td>
                                <td data-label="Recebido em" class="px-4 py-3 whitespace-nowrap tabular-nums">{{ $installment->paid_at->format('d/m/Y') }}</td>
                                <td data-label="Descrição" class="px-4 py-3">
                                    {{ $entry->description }}
                                    <span class="block text-xs text-slate-500">
                                        {{ $entry->payment_method->label() }}
                                        · {{ $entry->installments_count > 1 ? 'Parcela '.$installment->number.'/'.$entry->installments_count : 'À vista' }}
                                        @if ($entry->patient) · {{ $entry->patient->name }} @endif
                                    </span>
                                </td>
                                <td data-label="Valor" class="px-4 py-3 text-right font-medium whitespace-nowrap tabular-nums">{{ LocalizedDecimal::money($installment->amount) }}</td>
                                <td data-label="Taxa (R$)" class="px-4 py-3">
                                    @if ($installment->isReconciled())
                                        <span class="tabular-nums">{{ $installment->fee !== null ? LocalizedDecimal::money($installment->fee) : '—' }}</span>
                                    @else
                                        <label for="fee-{{ $installment->id }}" class="sr-only">Taxa de {{ $entry->description }}</label>
                                        <input id="fee-{{ $installment->id }}" name="fees[{{ $installment->id }}]" type="text" inputmode="decimal" autocomplete="off"
                                               value="{{ old('fees.'.$installment->id) }}" placeholder="0,00" @class(['input max-w-32', 'input-error' => $errors->has('fees.'.$installment->id)])>
                                        @error('fees.'.$installment->id)
                                            <p class="mt-1 text-sm text-red-700">{{ $message }}</p>
                                        @enderror
                                    @endif
                                </td>
                                <td data-label="Situação" class="px-4 py-3 whitespace-nowrap sm:px-6">
                                    @if ($installment->isReconciled())
                                        <span class="badge bg-emerald-100 text-emerald-800">Conferido em {{ $installment->reconciled_on->format('d/m/Y') }}</span>
                                        <button type="submit" form="unreconcile-{{ $installment->id }}" class="ml-2 cursor-pointer text-xs font-medium text-slate-600 hover:underline">Desfazer</button>
                                    @else
                                        <span class="badge bg-amber-100 text-amber-800">Pendente</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </section>

        @if ($selectable->isNotEmpty())
            <div class="flex flex-wrap items-end justify-end gap-3">
                <x-form.input name="reconciled_on" label="Data em que caiu no extrato" type="date" required :value="$today" :max="$today" />
                <button type="submit" class="btn btn-primary">Conciliar os marcados</button>
            </div>
        @endif
    </form>

    {{-- Fora do formulário de conciliação: formulário não pode conter outro. --}}
    @foreach ($installments->filter(fn ($installment) => $installment->isReconciled()) as $installment)
        <form method="POST" action="{{ route('finance.reconciliation.destroy', $installment) }}" id="unreconcile-{{ $installment->id }}" class="hidden">
            @csrf
            @method('DELETE')
        </form>
    @endforeach

    <div class="mt-4">
        {{ $installments->links() }}
    </div>
</x-finance.page>
