@php
    use App\Enums\FinancialEntryType;
    use App\Support\LocalizedDecimal;

    // Quem vê o financeiro sem ver o prontuário não ganha link para o atendimento.
    $canSeeRecords = auth()->user()->can('viewClinicalRecords', $patient);
    $canReceive = auth()->user()->hasPermission(App\Enums\Permission::FinanceReceive);
@endphp
<dl class="mt-4 grid grid-cols-3 gap-4">
    <div class="card px-4 py-3">
        <dt class="text-xs text-slate-500">Pago (menos estornos)</dt>
        <dd class="mt-0.5 text-xl font-semibold tabular-nums">{{ LocalizedDecimal::money($financialTotals->paid) }}</dd>
    </div>
    <div class="card px-4 py-3">
        <dt class="text-xs text-slate-500">Vencido</dt>
        <dd @class(['mt-0.5 text-xl font-semibold tabular-nums', 'text-red-700' => (float) $financialTotals->overdue > 0])>{{ LocalizedDecimal::money($financialTotals->overdue) }}</dd>
    </div>
    <div class="card px-4 py-3">
        <dt class="text-xs text-slate-500">A vencer</dt>
        <dd @class(['mt-0.5 text-xl font-semibold tabular-nums', 'text-amber-700' => (float) $financialTotals->upcoming > 0])>{{ LocalizedDecimal::money($financialTotals->upcoming) }}</dd>
    </div>
</dl>

<section class="card mt-4 relative overflow-x-auto" aria-labelledby="financeiro-do-paciente">
    <h2 id="financeiro-do-paciente" class="sr-only">Histórico financeiro</h2>
    @if ($records->isEmpty())
        <p class="px-4 py-10 text-center text-sm text-slate-500">Nenhum lançamento financeiro ligado a este paciente.</p>
    @else
        <table class="table-stack w-full min-w-2xl text-left text-sm">
            <thead class="border-b border-slate-200 bg-slate-50 text-xs tracking-wide text-slate-500 uppercase">
                <tr>
                    <th scope="col" class="px-4 py-3 font-medium">Vencimento</th>
                    <th scope="col" class="px-4 py-3 font-medium">Descrição</th>
                    <th scope="col" class="px-4 py-3 text-right font-medium">Valor</th>
                    <th scope="col" class="px-4 py-3 font-medium">Situação</th>
                    @if ($canReceive)
                        <th scope="col" class="px-4 py-3"><span class="sr-only">Ações</span></th>
                    @endif
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @foreach ($records as $installment)
                    @php
                        $entry = $installment->entry;
                        $badge = $installment->statusBadge();
                    @endphp
                    <tr>
                        <td data-label="Vencimento" class="px-4 py-3 whitespace-nowrap text-slate-600 tabular-nums">{{ $installment->due_date->format('d/m/Y') }}</td>
                        <td data-label="Descrição" class="px-4 py-3">
                            @if ($entry->treatment_id && $canSeeRecords)
                                <a href="{{ route('patients.treatments.show', [$patient, $entry->treatment_id]) }}" class="font-medium text-teal-800 hover:underline">{{ $entry->description }}</a>
                            @else
                                <span class="font-medium">{{ $entry->description }}</span>
                            @endif
                            <span class="block text-xs text-slate-500">
                                {{ $entry->payment_method->label() }} · {{ $entry->installments_count > 1 ? 'Parcela '.$installment->number.'/'.$entry->installments_count : 'À vista' }}
                            </span>
                        </td>
                        {{-- Estorno é dinheiro devolvido ao paciente: sai com sinal de menos. --}}
                        <td data-label="Valor" class="px-4 py-3 text-right whitespace-nowrap tabular-nums">{{ $entry->type === FinancialEntryType::Expense ? '− ' : '' }}{{ LocalizedDecimal::money($installment->amount) }}</td>
                        <td data-label="Situação" class="px-4 py-3 whitespace-nowrap">
                            <span class="badge {{ $badge['classes'] }}">{{ $badge['label'] }}</span>
                            @if ($installment->isPaid())
                                <span class="ml-1 text-xs text-slate-500">em {{ $installment->paid_at->format('d/m/Y') }}</span>
                            @endif
                        </td>
                        @if ($canReceive)
                            <td class="px-4 py-3 text-right whitespace-nowrap">
                                @if (! $installment->isPaid() && $entry->type === FinancialEntryType::Income)
                                    @include('finance._receive_button')
                                @endif
                            </td>
                        @endif
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</section>

<div class="mt-4">{{ $records->links() }}</div>

@if ($canReceive)
    @include('finance._payment_dialog')
@endif
