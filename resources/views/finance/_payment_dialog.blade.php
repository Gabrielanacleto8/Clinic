@php
    use App\Support\ClinicTime;
    use App\Support\LocalizedDecimal;

    // Depois de um erro de validação o resumo é montado aqui, não pelo JS do clique.
    // Só a parcela que o usuário pode receber: o id vem do envio anterior e não é confiável.
    $oldInstallment = old('_dialog') === 'installment-payment' && is_numeric(old('installment_id'))
        ? App\Models\FinancialInstallment::query()->with(['entry' => fn ($query) => $query->withCount('installments')])->find((int) old('installment_id'))
        : null;
    $oldInstallment = $oldInstallment && auth()->user()->can('receive', $oldInstallment->entry) ? $oldInstallment : null;
@endphp
{{-- Diálogo de recebimento, usado no Financeiro e na ficha do paciente. O botão que
     abre passa installment_id, a forma lançada e o resumo (ver app.js). --}}
<x-dialog id="installment-payment" title="Registrar pagamento">
    <form method="POST" action="{{ route('finance.payments.store') }}" class="space-y-4" novalidate>
        @csrf
        <input type="hidden" name="_dialog" value="installment-payment">
        <input type="hidden" name="installment_id" value="{{ old('installment_id') }}">

        <p class="text-sm text-slate-700" data-dialog-summary>
            @if ($oldInstallment)
                {{ $oldInstallment->entry->description }}
                · {{ $oldInstallment->entry->installments_count > 1 ? 'Parcela '.$oldInstallment->number.'/'.$oldInstallment->entry->installments_count : 'À vista' }}
                · {{ LocalizedDecimal::money($oldInstallment->amount) }}
            @endif
        </p>
        @error('installment_id')
            <p class="text-sm text-red-700">{{ $message }}</p>
        @enderror

        <x-form.select scope="installment-payment" name="payment_method" label="Forma de pagamento" :options="App\Enums\PaymentMethod::options()"
                       :selected="$oldInstallment?->entry->payment_method->value" required />
        <p class="-mt-2 text-xs text-slate-500">Confirme como o paciente pagou. Se for diferente do lançado, o lançamento é corrigido.</p>

        <x-form.input scope="installment-payment" name="paid_at" label="Data do pagamento" type="date" required
                      :value="ClinicTime::today()" :max="ClinicTime::today()" />

        <div class="flex justify-end gap-2 pt-2">
            <button type="button" class="btn btn-secondary" data-dialog-close>Cancelar</button>
            <button type="submit" class="btn btn-primary">Confirmar</button>
        </div>
    </form>
</x-dialog>
