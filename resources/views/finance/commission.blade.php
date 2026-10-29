@php
    use App\Models\FinancialEntry;
    use App\Support\ClinicTime;
    use App\Support\LocalizedDecimal;

    $pending = round($rows->sum('pending'), 2);
    $canManage = auth()->user()->can('manage', FinancialEntry::class);
    $untilLabel = Carbon\CarbonImmutable::createFromFormat('!Y-m-d', $until)->format('d/m/Y');
@endphp
<x-finance.page active="comissoes" :title="'Comissões · '.$professional->name" :subtitle="'Atendimentos até '.$untilLabel">
    <a href="{{ route('finance.commissions.index') }}" class="mt-4 inline-block text-sm text-slate-600 hover:underline">← Todos os profissionais</a>

    <form method="GET" action="{{ route('finance.commissions.show', $professional) }}" class="mt-3 flex flex-wrap items-end gap-2" role="search">
        <div>
            <label for="ate" class="label">Atendimentos até</label>
            <input id="ate" name="ate" type="date" value="{{ $until }}" max="{{ $today }}" class="input">
        </div>
        <button type="submit" class="btn btn-secondary">Ver</button>
        @error('ate')
            <p class="w-full text-sm text-red-700">{{ $message }}</p>
        @enderror
    </form>

    <dl class="mt-6 grid grid-cols-2 gap-3 lg:grid-cols-4">
        @foreach (['Atendimentos' => $rows->count(), 'Comissão' => LocalizedDecimal::money($rows->sum('earned')), 'Já repassado' => LocalizedDecimal::money($rows->sum('paid')), 'A repassar' => LocalizedDecimal::money($pending)] as $label => $value)
            <div class="card px-4 py-3">
                <dt class="text-xs text-slate-500">{{ $label }}</dt>
                <dd class="mt-0.5 text-lg font-semibold tabular-nums">{{ $value }}</dd>
            </div>
        @endforeach
    </dl>

    @if ($canManage && $pending > 0)
        <section class="card mt-6 p-6" aria-labelledby="repassar">
            <h2 id="repassar" class="text-sm font-semibold tracking-wide text-slate-500 uppercase">Registrar repasse de {{ LocalizedDecimal::money($pending) }}</h2>
            <p class="mt-1 text-sm text-slate-600">Paga o que está a repassar nos atendimentos até {{ $untilLabel }} e lança a despesa em Comissões e repasses.</p>
            <form method="POST" action="{{ route('finance.commissions.payouts.store', $professional) }}" class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-6" novalidate
                  data-confirm="Registrar o repasse de {{ LocalizedDecimal::money($pending) }} para {{ $professional->name }}?">
                @csrf
                <input type="hidden" name="until" value="{{ $until }}">
                @error('until')
                    <p class="text-sm text-red-700 sm:col-span-6">{{ $message }}</p>
                @enderror
                <x-form.select class="sm:col-span-3" name="payment_method" label="Forma de pagamento" :options="$paymentMethods" required placeholder="Selecione" />
                <label class="flex items-center gap-2 text-sm sm:col-span-3 sm:pt-7">
                    <input type="hidden" name="paid_now" value="0">
                    <input type="checkbox" name="paid_now" value="1" class="size-4 rounded border-slate-300 accent-brand" @checked(old('paid_now'))>
                    Já pago hoje
                </label>
                @error('first_installment_paid')
                    <p class="text-sm text-red-700 sm:col-span-6">{{ $message }}</p>
                @enderror
                <div class="flex justify-end sm:col-span-6">
                    <button type="submit" class="btn btn-primary">Registrar repasse</button>
                </div>
            </form>
        </section>
    @elseif ($pending < 0)
        <p class="mt-6 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900" role="note">
            Saldo negativo de {{ LocalizedDecimal::money(abs($pending)) }}: comissão já repassada de atendimento anulado ou estornado. É descontado no próximo repasse.
        </p>
    @endif

    <section class="card mt-6 relative overflow-x-auto" aria-labelledby="por-atendimento">
        <h2 id="por-atendimento" class="px-4 pt-5 text-sm font-semibold tracking-wide text-slate-500 uppercase sm:px-6">Por atendimento</h2>
        @if ($rows->isEmpty())
            <p class="px-4 pt-4 pb-6 text-sm text-slate-600 sm:px-6">Nenhum atendimento com comissão até esta data.</p>
        @else
            <table class="table-stack mt-3 w-full min-w-3xl text-left text-sm">
                <thead class="border-y border-slate-200 bg-slate-50 text-xs tracking-wide text-slate-500 uppercase">
                    <tr>
                        <th scope="col" class="px-4 py-3 font-medium sm:px-6">Atendimento</th>
                        <th scope="col" class="px-4 py-3 text-right font-medium">Valor</th>
                        <th scope="col" class="px-4 py-3 text-right font-medium">Recebido</th>
                        <th scope="col" class="px-4 py-3 text-right font-medium">%</th>
                        <th scope="col" class="px-4 py-3 text-right font-medium">Comissão</th>
                        <th scope="col" class="px-4 py-3 text-right font-medium">Repassado</th>
                        <th scope="col" class="px-4 py-3 text-right font-medium sm:px-6">A repassar</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($rows as $row)
                        <tr @class(['text-slate-400' => $row->voided])>
                            <td data-label="Atendimento" class="px-4 py-3 sm:px-6">
                                nº {{ $row->treatment_id }} · {{ $row->procedure }}
                                <span class="block text-xs text-slate-500">{{ ClinicTime::format(Carbon\CarbonImmutable::parse($row->performed_at, 'UTC'), 'd/m/Y') }}{{ $row->voided ? ' · anulado' : '' }}</span>
                            </td>
                            <td data-label="Valor" class="px-4 py-3 text-right tabular-nums">{{ LocalizedDecimal::money($row->value) }}</td>
                            <td data-label="Recebido" class="px-4 py-3 text-right tabular-nums">{{ LocalizedDecimal::money($row->base) }}</td>
                            <td data-label="%" class="px-4 py-3 text-right tabular-nums">{{ number_format($row->rate, 2, ',', '.') }}</td>
                            <td data-label="Comissão" class="px-4 py-3 text-right tabular-nums">{{ LocalizedDecimal::money($row->earned) }}</td>
                            <td data-label="Repassado" class="px-4 py-3 text-right tabular-nums text-slate-600">{{ LocalizedDecimal::money($row->paid) }}</td>
                            <td data-label="A repassar" @class(['px-4 py-3 text-right font-semibold tabular-nums sm:px-6', 'text-red-700' => $row->pending < 0])>{{ LocalizedDecimal::money($row->pending) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </section>

    @if ($payouts->isNotEmpty())
        <section class="card mt-6 p-6" aria-labelledby="repasses">
            <h2 id="repasses" class="text-sm font-semibold tracking-wide text-slate-500 uppercase">Repasses</h2>
            <ul class="mt-3 divide-y divide-slate-100 text-sm">
                @foreach ($payouts as $payout)
                    <li class="flex flex-wrap items-center justify-between gap-2 py-2">
                        <span>
                            {{ ClinicTime::format($payout->created_at, 'd/m/Y') }} · atendimentos até {{ $payout->until->format('d/m/Y') }}
                            · <span class="font-medium tabular-nums">{{ LocalizedDecimal::money($payout->entry?->total() ?? 0) }}</span>
                            <span @class(['badge ml-1', 'bg-emerald-100 text-emerald-800' => $payout->entry?->hasPaidInstallment(), 'bg-amber-100 text-amber-800' => ! $payout->entry?->hasPaidInstallment()])>{{ $payout->entry?->hasPaidInstallment() ? 'Pago' : 'A pagar' }}</span>
                        </span>
                        @if ($canManage && ! $payout->entry?->hasPaidInstallment())
                            <form method="POST" action="{{ route('finance.commissions.payouts.destroy', $payout) }}"
                                  data-confirm="Excluir este repasse? A despesa sai do financeiro e a comissão volta a ficar a repassar.">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="cursor-pointer text-sm font-medium text-red-700 hover:underline">Excluir</button>
                            </form>
                        @endif
                    </li>
                @endforeach
            </ul>
        </section>
    @endif
</x-finance.page>
