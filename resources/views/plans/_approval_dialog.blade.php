@php
    use App\Enums\Permission;
    use App\Support\ClinicTime;
    use App\Support\InstallmentPlan;
    use App\Support\LocalizedDecimal;

    // Diálogo "Converter em venda". Na página do orçamento, $plan é o próprio e o
    // formulário já aponta para ele. Na lista, o botão da linha define o destino
    // (data-dialog-action) e o valor (data-dialog-values); $plan é nulo, ou o
    // orçamento do envio que voltou com erro, para o diálogo reabrir no lugar certo.
    $action = $plan ? route('patients.plans.approve', [$plan->patient, $plan]) : '';
    $total = $plan?->total() ?? '0';
    $hasAmount = $plan === null || (float) $total > 0;
    // Na lista, sem orçamento para apontar (envio com plan_id inválido), o
    // diálogo não reabre sozinho: o botão da linha é o único caminho.
    $reopens = ($backToList ?? false) ? $plan !== null : true;
@endphp
<x-dialog id="plan-approval" title="Converter em venda" :autoshow="$autoshow ?? false" :reopens="$reopens">
    <form method="POST" action="{{ $action }}" class="space-y-4" novalidate>
        @csrf
        <input type="hidden" name="_dialog" value="plan-approval">
        @if ($backToList ?? false)
            <input type="hidden" name="voltar" value="lista">
            <input type="hidden" name="plan_id" value="{{ old('plan_id', $plan?->id) }}">
        @endif
        <p class="text-sm text-slate-700">
            O orçamento vira um pacote vendido e a cobrança de <span data-dialog-summary>{{ LocalizedDecimal::money($total) }}</span> é lançada no financeiro hoje.
            Cada atendimento registrado depois usa uma sessão, sem nova cobrança.
        </p>
        @if ($hasAmount)
            <x-form.select scope="plan-approval" name="payment_method" label="Forma de pagamento" :options="$paymentMethods" required placeholder="Selecione" />
            <x-form.select scope="plan-approval" name="installments" label="Parcelas" required selected="1"
                           :options="collect(range(1, InstallmentPlan::MAX_INSTALLMENTS))->mapWithKeys(fn ($n) => [$n => $n === 1 ? 'À vista' : $n.'x'])->all()" />
            <x-form.input scope="plan-approval" name="first_due_date" label="Primeiro vencimento" type="date" required :value="ClinicTime::today()"
                          hint="As demais parcelas vencem de mês em mês." />
            @if (auth()->user()->hasPermission(Permission::FinanceReceive))
                <label class="flex items-center gap-2 text-sm">
                    <input type="hidden" name="first_installment_paid" value="0">
                    <input type="checkbox" name="first_installment_paid" value="1" class="size-4 rounded border-slate-300 accent-brand">
                    Pagamento (ou primeira parcela) já recebido
                </label>
            @endif
        @else
            {{-- Pacote sem valor: nada a cobrar, mas os campos seguem obrigatórios no envio. --}}
            <input type="hidden" name="payment_method" value="{{ App\Enums\PaymentMethod::Cash->value }}">
            <input type="hidden" name="installments" value="1">
            <input type="hidden" name="first_due_date" value="{{ ClinicTime::today() }}">
            <p class="text-sm text-slate-600">Pacote sem valor: nenhuma cobrança será lançada.</p>
        @endif
        <div class="flex justify-end gap-2 pt-2">
            <button type="button" class="btn btn-secondary" data-dialog-close>Cancelar</button>
            <button type="submit" class="btn btn-primary">Converter em venda e lançar cobrança</button>
        </div>
    </form>
</x-dialog>
