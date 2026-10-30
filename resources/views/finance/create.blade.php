@php
    use App\Enums\FinancialEntryType;

    $isIncome = $type === FinancialEntryType::Income;
    $defaults ??= [];
    $tab = fn (bool $active) => 'rounded-lg px-4 py-2 text-sm font-medium '.($active ? 'bg-brand text-on-brand' : 'text-slate-700 hover:bg-slate-100');
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
                @include('finance._entry_fields')
            </section>

            <div class="flex flex-wrap items-center justify-end gap-3">
                <a href="{{ route('finance.index') }}" class="btn btn-secondary">Cancelar</a>
                <button type="submit" class="btn btn-primary">{{ $isIncome ? 'Lançar receita' : 'Lançar despesa' }}</button>
            </div>
        </form>
    </div>
</x-layouts.app>
