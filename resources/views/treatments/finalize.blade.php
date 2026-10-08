@php
    use App\Support\ClinicTime;
    use App\Support\InstallmentPlan;
    use App\Support\LocalizedDecimal;

    $installmentOptions = collect(range(1, InstallmentPlan::MAX_INSTALLMENTS))->mapWithKeys(fn ($count) => [$count => $count === 1 ? 'À vista' : $count.'x'])->all();
@endphp
<x-layouts.app :title="'Finalizar atendimento · '.$patient->name">
    <div class="mx-auto max-w-3xl">
        <a href="{{ route('patients.treatments.show', [$patient, $treatment]) }}" class="text-sm text-slate-600 hover:underline">← Atendimento</a>
        <h1 class="mt-1 text-xl font-semibold tracking-tight">Finalizar atendimento</h1>
        <p class="mt-1 text-sm text-slate-600">
            {{ $treatment->procedure->name }} · {{ $patient->name }} · {{ ClinicTime::format($treatment->performed_at, 'd/m/Y') }} · {{ $treatment->professional->name }}
        </p>

        <p class="mt-6 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900" role="note">
            Confira antes de confirmar. Depois de finalizado, o atendimento vira histórico permanente e não pode mais ser editado.
        </p>

        @foreach (['treatment', 'products'] as $field)
            @error($field)
                <p class="mt-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900" role="alert">{{ $message }}</p>
            @enderror
        @endforeach

        <section class="card mt-6 overflow-x-auto">
            <h2 class="px-6 pt-6 text-sm font-semibold tracking-wide text-slate-500 uppercase">Baixa no estoque</h2>
            @if ($treatment->products->isEmpty())
                <p class="px-6 pt-4 pb-6 text-sm text-slate-600">Nenhum produto neste atendimento. Nada será baixado do estoque.</p>
            @else
                <table class="mt-4 w-full min-w-lg text-left text-sm">
                    <thead class="border-y border-slate-200 bg-slate-50 text-xs tracking-wide text-slate-500 uppercase">
                        <tr>
                            <th scope="col" class="px-6 py-3 font-medium">Produto e lote</th>
                            <th scope="col" class="px-4 py-3 text-right font-medium">Baixar</th>
                            <th scope="col" class="px-6 py-3 text-right font-medium">Saldo do lote</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($treatment->products as $used)
                            @php
                                $batch = $used->batch;
                                $unit = $batch->product->unit->symbol();
                                $short = (float) $batch->quantity < (float) $used->quantity;
                            @endphp
                            <tr>
                                <td class="px-6 py-3">
                                    <p class="font-medium">{{ $batch->product->name }}</p>
                                    <p class="mt-0.5 text-xs text-slate-500">
                                        Lote {{ $batch->batch_code }} · {{ $batch->expires_on ? 'validade '.$batch->expires_on->format('d/m/Y') : 'sem validade' }}
                                        @if ($batch->isExpired())
                                            <span class="badge ml-1 bg-red-100 text-red-800">Vencido</span>
                                        @endif
                                    </p>
                                </td>
                                <td class="px-4 py-3 text-right whitespace-nowrap tabular-nums">{{ LocalizedDecimal::quantity($used->quantity) }} {{ $unit }}</td>
                                <td @class(['px-6 py-3 text-right whitespace-nowrap tabular-nums', 'font-medium text-red-700' => $short])>
                                    {{ LocalizedDecimal::quantity($batch->quantity) }} {{ $unit }}
                                    @if ($short)
                                        <span class="block text-xs font-normal">insuficiente</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
            @can('update', $treatment)
                <p class="px-6 py-4 text-sm">
                    <a href="{{ route('patients.treatments.edit', [$patient, $treatment]) }}" class="text-teal-800 hover:underline">Corrigir produtos ou quantidades</a>
                </p>
            @endcan
        </section>

        <form method="POST" action="{{ route('patients.treatments.finalization.store', [$patient, $treatment]) }}" class="mt-6 space-y-6" novalidate
              data-confirm="Finalizar o atendimento? O estoque será baixado e o lançamento financeiro será gerado.">
            @csrf

            <section class="card p-6">
                <h2 class="text-sm font-semibold tracking-wide text-slate-500 uppercase">Cobrança</h2>
                <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-6">
                    <x-form.input class="md:col-span-2" name="amount" label="Valor do atendimento (R$)" required inputmode="decimal" autocomplete="off"
                                  :value="LocalizedDecimal::moneyInput($treatment->procedure->price)" hint="Use 0 para atendimento sem cobrança." />
                    <x-form.select class="md:col-span-2" name="payment_method" label="Forma de pagamento" :options="App\Enums\PaymentMethod::options()" placeholder="Selecione" />
                    <x-form.select class="md:col-span-2" name="installments" label="Parcelas" :options="$installmentOptions" selected="1" />
                    <x-form.input class="md:col-span-2" name="first_due_date" label="Primeiro vencimento" type="date" :value="ClinicTime::today()"
                                  hint="As demais parcelas vencem de mês em mês." />
                    <div class="md:col-span-4 md:pt-7">
                        <label class="flex items-center gap-2 text-sm">
                            <input type="hidden" name="first_installment_paid" value="0">
                            <input type="checkbox" name="first_installment_paid" value="1" class="size-4 rounded border-slate-300 accent-teal-700"
                                   @checked(old('first_installment_paid', true))>
                            Pagamento (ou primeira parcela) já recebido
                        </label>
                    </div>
                </div>
            </section>

            <div class="flex flex-wrap items-center justify-end gap-3">
                <a href="{{ route('patients.treatments.show', [$patient, $treatment]) }}" class="btn btn-secondary">Voltar</a>
                <button type="submit" class="btn btn-primary">Finalizar atendimento</button>
            </div>
        </form>
    </div>
</x-layouts.app>
