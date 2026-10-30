@php
    use App\Enums\FinancialCategory;
    use App\Enums\FinancialEntryType;
    use App\Support\ClinicTime;
    use App\Support\InstallmentPlan;

    // Campos do lançamento manual, na página e nas janelas de nova despesa e nova receita.
    // $scope é o id da janela (null na página): valores antigos e erros só valem para ela.
    $scope ??= null;
    $defaults ??= [];
    $patient ??= null;
    $isIncome = $type === FinancialEntryType::Income;
    $field = fn (string $name) => $scope ? "{$scope}-{$name}" : $name;
    $installmentOptions = collect(range(1, InstallmentPlan::MAX_INSTALLMENTS))->mapWithKeys(fn ($count) => [$count => $count === 1 ? 'À vista' : $count.'x'])->all();
    $paidChecked = ($scope === null || old('_dialog') === $scope) && old('first_installment_paid');
@endphp
<div class="grid grid-cols-1 gap-4 md:grid-cols-6">
    <x-form.input class="md:col-span-4" name="description" :id="$field('description')" :scope="$scope" label="Descrição" required maxlength="200" autocomplete="off"
                  :value="$defaults['description'] ?? null" :placeholder="$isIncome ? 'Ex.: venda de produto' : 'Ex.: aluguel de outubro'" />
    <x-form.select class="md:col-span-2" name="category" :id="$field('category')" :scope="$scope" label="Categoria" :options="FinancialCategory::optionsFor($type)"
                   required placeholder="Selecione" :selected="$defaults['category'] ?? null" />

    @if ($isIncome)
        {{-- Receita de paciente fora do atendimento (venda de produto, pacote) entra na ficha dele. --}}
        <x-patient-picker class="md:col-span-6" :patient="$patient" :scope="$scope" :required="false" />
    @endif

    <x-form.input class="md:col-span-2" name="total" :id="$field('total')" :scope="$scope" label="Valor total (R$)" required inputmode="decimal" autocomplete="off" placeholder="0,00"
                  :value="$defaults['total'] ?? null" />
    <x-form.input class="md:col-span-2" name="issued_on" :id="$field('issued_on')" :scope="$scope" label="Data do lançamento" type="date" required
                  :value="ClinicTime::today()" :max="ClinicTime::today()" />
    <x-form.select class="md:col-span-2" name="payment_method" :id="$field('payment_method')" :scope="$scope" label="Forma de pagamento" :options="App\Enums\PaymentMethod::options()"
                   required placeholder="Selecione" :selected="$defaults['payment_method'] ?? null" />

    <x-form.select class="md:col-span-2" name="installments" :id="$field('installments')" :scope="$scope" label="Parcelas" :options="$installmentOptions"
                   :selected="$defaults['installments'] ?? 1" required />
    <x-form.input class="md:col-span-2" name="first_due_date" :id="$field('first_due_date')" :scope="$scope" label="Primeiro vencimento" type="date" required
                  :value="ClinicTime::today()" hint="As demais parcelas vencem de mês em mês." />
    <div class="md:col-span-2 md:pt-7">
        <label class="flex items-center gap-2 text-sm">
            <input type="hidden" name="first_installment_paid" value="0">
            <input type="checkbox" name="first_installment_paid" value="1" class="size-4 rounded border-slate-300 accent-brand" @checked($paidChecked)>
            {{ $isIncome ? 'Primeira parcela já recebida' : 'Primeira parcela já paga' }}
        </label>
        <p class="mt-1 text-xs text-slate-500">
            À vista, marca o valor inteiro como pago na data do lançamento.
            @if ($isIncome)
                No cartão de crédito, todas as parcelas ficam como recebidas.
            @endif
        </p>
    </div>
</div>
