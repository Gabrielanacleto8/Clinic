@php
    use App\Enums\FinancialCategory;
    use App\Enums\FinancialEntryType;
    use App\Support\ClinicTime;
    use App\Support\InstallmentPlan;

    $isIncome = $type === FinancialEntryType::Income;
    $installmentOptions = collect(range(1, InstallmentPlan::MAX_INSTALLMENTS))->mapWithKeys(fn ($count) => [$count => $count === 1 ? 'À vista' : $count.'x'])->all();
    $defaults ??= [];
    $tab = fn (bool $active) => 'rounded-lg px-4 py-2 text-sm font-medium '.($active ? 'bg-teal-700 text-white' : 'text-slate-700 hover:bg-slate-100');
@endphp
<x-layouts.app :title="$isIncome ? 'Nova receita' : 'Nova despesa'">
    <div class="mx-auto max-w-3xl">
        <a href="{{ route('finance.index') }}" class="text-sm text-slate-600 hover:underline">← Financeiro</a>
        <h1 class="mt-1 text-xl font-semibold tracking-tight">{{ $isIncome ? 'Nova receita' : 'Nova despesa' }}</h1>

        {{-- As categorias dependem do tipo, então trocar de tipo recarrega o formulário. --}}
        @if ($defaults !== [])
            <p class="mt-3 rounded-lg border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-900" role="note">Dados copiados do lançamento anterior, com a data de hoje. Confira o valor e o vencimento antes de lançar.</p>
        @endif

        <nav class="mt-4 flex gap-2" aria-label="Tipo de lançamento">
            <a href="{{ route('finance.entries.create', ['tipo' => FinancialEntryType::Expense->value]) }}" class="{{ $tab(! $isIncome) }}" @if (! $isIncome) aria-current="page" @endif>Despesa</a>
            <a href="{{ route('finance.entries.create', ['tipo' => FinancialEntryType::Income->value]) }}" class="{{ $tab($isIncome) }}" @if ($isIncome) aria-current="page" @endif>Receita</a>
        </nav>

        <form method="POST" action="{{ route('finance.entries.store') }}" class="mt-6 space-y-6" novalidate>
            @csrf
            <input type="hidden" name="type" value="{{ $type->value }}">
            @error('type')
                <p class="text-sm text-red-700">{{ $message }}</p>
            @enderror

            <section class="card p-6">
                <div class="grid grid-cols-1 gap-4 md:grid-cols-6">
                    <x-form.input class="md:col-span-4" name="description" label="Descrição" required maxlength="200" autocomplete="off" :value="$defaults['description'] ?? null"
                                  :placeholder="$isIncome ? 'Ex.: venda de produto' : 'Ex.: aluguel de outubro'" />
                    <x-form.select class="md:col-span-2" name="category" label="Categoria" :options="FinancialCategory::optionsFor($type)" required placeholder="Selecione" :selected="$defaults['category'] ?? null" />

                    @if ($isIncome)
                        {{-- Receita de paciente fora do atendimento (venda de produto, pacote) entra na ficha dele. --}}
                        <x-patient-picker class="md:col-span-6" :patient="$patient" :required="false" />
                    @endif

                    <x-form.input class="md:col-span-2" name="total" label="Valor total (R$)" required inputmode="decimal" autocomplete="off" placeholder="0,00" :value="$defaults['total'] ?? null" />
                    <x-form.input class="md:col-span-2" name="issued_on" label="Data do lançamento" type="date" required :value="ClinicTime::today()" :max="ClinicTime::today()" />
                    <x-form.select class="md:col-span-2" name="payment_method" label="Forma de pagamento" :options="App\Enums\PaymentMethod::options()" required placeholder="Selecione" :selected="$defaults['payment_method'] ?? null" />

                    <x-form.select class="md:col-span-2" name="installments" label="Parcelas" :options="$installmentOptions" :selected="$defaults['installments'] ?? 1" required />
                    <x-form.input class="md:col-span-2" name="first_due_date" label="Primeiro vencimento" type="date" required :value="ClinicTime::today()"
                                  hint="As demais parcelas vencem de mês em mês." />
                    <div class="md:col-span-2 md:pt-7">
                        <label class="flex items-center gap-2 text-sm">
                            <input type="hidden" name="first_installment_paid" value="0">
                            <input type="checkbox" name="first_installment_paid" value="1" class="size-4 rounded border-slate-300 accent-teal-700"
                                   @checked(old('first_installment_paid'))>
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
            </section>

            <div class="flex flex-wrap items-center justify-end gap-3">
                <a href="{{ route('finance.index') }}" class="btn btn-secondary">Cancelar</a>
                <button type="submit" class="btn btn-primary">{{ $isIncome ? 'Lançar receita' : 'Lançar despesa' }}</button>
            </div>
        </form>
    </div>
</x-layouts.app>
