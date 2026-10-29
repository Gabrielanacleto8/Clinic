@php
    use App\Enums\FinancialCategory;
    use App\Enums\FinancialEntryType;
    use App\Enums\PaymentMethod;
    use App\Support\ClinicTime;
    use App\Support\LocalizedDecimal;

    // Campos da despesa recorrente; $scope é o id do diálogo e $expense a recorrência (nova ou em edição).
    $inScope = old('_dialog') === $scope;
@endphp
<div class="grid grid-cols-1 gap-4 sm:grid-cols-6">
    <x-form.input :scope="$scope" id="{{ $scope }}-description" class="sm:col-span-6" name="description" label="Descrição" required maxlength="200"
                  :value="$expense->description" placeholder="Ex.: Aluguel da sala, contador, sistema de agenda" />
    <x-form.select :scope="$scope" id="{{ $scope }}-category" class="sm:col-span-3" name="category" label="Categoria" required placeholder="Selecione"
                   :options="FinancialCategory::optionsFor(FinancialEntryType::Expense)" :selected="$expense->category?->value" />
    <x-form.input :scope="$scope" id="{{ $scope }}-amount" class="sm:col-span-3" name="amount" label="Valor por mês (R$)" required inputmode="decimal" autocomplete="off"
                  :value="LocalizedDecimal::moneyInput($expense->amount)" />
    <x-form.select :scope="$scope" id="{{ $scope }}-payment_method" class="sm:col-span-3" name="payment_method" label="Forma de pagamento" required
                   :options="PaymentMethod::options()" :selected="$expense->payment_method?->value" placeholder="Selecione" />
    <x-form.input :scope="$scope" id="{{ $scope }}-due_day" class="sm:col-span-3" name="due_day" label="Dia do vencimento" type="number" min="1" max="28" required
                  :value="$expense->due_day" hint="De 1 a 28." />
    <x-form.input :scope="$scope" id="{{ $scope }}-starts_on" class="sm:col-span-3" name="starts_on" label="Início" type="date" required
                  :value="$expense->starts_on?->format('Y-m-d') ?? ClinicTime::today()" />
    <x-form.input :scope="$scope" id="{{ $scope }}-ends_on" class="sm:col-span-3" name="ends_on" label="Fim (opcional)" type="date"
                  :value="$expense->ends_on?->format('Y-m-d')" hint="Último mês lançado." />
    <label class="flex items-center gap-2 text-sm sm:col-span-6">
        <input type="hidden" name="is_active" value="0">
        <input type="checkbox" name="is_active" value="1" class="size-4 rounded border-slate-300 accent-brand"
               @checked($inScope ? old('is_active', true) : ($expense->exists ? $expense->is_active : true))>
        Ativa: lança a despesa todo mês
    </label>
</div>
