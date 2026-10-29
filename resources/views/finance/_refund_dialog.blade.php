@php
    use App\Enums\PaymentMethod;
    use App\Enums\RefundReason;
    use App\Support\ClinicTime;
@endphp
{{-- Estorno ao paciente. O botão que abre passa entry_id, a forma da cobrança e o resumo (ver app.js). --}}
<x-dialog id="refund" title="Estornar ao paciente">
    <form method="POST" action="{{ route('finance.refunds.store') }}" class="space-y-4" novalidate data-dialog-reset>
        @csrf
        <input type="hidden" name="_dialog" value="refund">
        <input type="hidden" name="entry_id" value="{{ old('entry_id') }}">
        <p class="text-sm text-slate-700" data-dialog-summary></p>
        <p class="text-xs text-slate-500">Devolve ao paciente parte ou todo o valor pago. Entra como saída no caixa do dia e desconta a comissão do atendimento. Para corrigir um pagamento registrado por engano, use "Desfazer".</p>
        @error('entry_id')
            <p class="text-sm text-red-700">{{ $message }}</p>
        @enderror
        <x-form.input scope="refund" name="amount" label="Valor devolvido (R$)" required inputmode="decimal" autocomplete="off" />
        <x-form.select scope="refund" name="reason" label="Motivo" :options="RefundReason::options()" required placeholder="Selecione" />
        <x-form.select scope="refund" name="payment_method" label="Forma da devolução" :options="PaymentMethod::options()" required />
        <x-form.input scope="refund" name="refunded_on" label="Data da devolução" type="date" required :value="ClinicTime::today()" :max="ClinicTime::today()" />
        <div class="flex justify-end gap-2 pt-2">
            <button type="button" class="btn btn-secondary" data-dialog-close>Cancelar</button>
            <button type="submit" class="btn btn-primary">Registrar estorno</button>
        </div>
    </form>
</x-dialog>
