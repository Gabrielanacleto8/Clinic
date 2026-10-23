@php
    use App\Enums\FinancialCategory;
    use App\Support\LocalizedDecimal;
@endphp
<x-layouts.app :title="'Editar '.$entry->description">
    <div class="mx-auto max-w-3xl">
        <a href="{{ route('finance.index', ['mes' => $entry->issued_on->format('Y-m')]) }}" class="text-sm text-slate-600 hover:underline">← Financeiro</a>
        <h1 class="mt-1 text-xl font-semibold tracking-tight">Editar lançamento</h1>
        <p class="mt-1 text-sm text-slate-600">
            {{ $entry->type->label() }} de {{ LocalizedDecimal::money($entry->total()) }} em {{ $entry->installments->count() === 1 ? 'parcela única' : $entry->installments->count().' parcelas' }}
            · {{ $entry->payment_method->label() }} · lançado em {{ $entry->issued_on->format('d/m/Y') }}.
            Valor, parcelas e forma de pagamento não mudam: para isso, exclua e lance de novo.
        </p>

        <form method="POST" action="{{ route('finance.entries.update', $entry) }}" class="mt-6 space-y-6" novalidate>
            @csrf
            @method('PUT')

            <section class="card p-6">
                <div class="grid grid-cols-1 gap-4 md:grid-cols-6">
                    <x-form.input class="md:col-span-4" name="description" label="Descrição" required maxlength="200" autocomplete="off" :value="$entry->description" />
                    <x-form.select class="md:col-span-2" name="category" label="Categoria" :options="FinancialCategory::optionsFor($entry->type)" required :selected="$entry->category->value" />
                </div>
            </section>

            <section class="card p-6">
                <h2 class="text-sm font-semibold tracking-wide text-slate-500 uppercase">Vencimentos</h2>
                <p class="mt-1 text-sm text-slate-600">Só parcelas em aberto podem ser prorrogadas.</p>
                @error('due_dates')
                    <p class="mt-2 text-sm text-red-700">{{ $message }}</p>
                @enderror
                <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-3">
                    @foreach ($entry->installments as $installment)
                        @if ($installment->isPaid())
                            <div>
                                <p class="label">Parcela {{ $installment->number }} · {{ LocalizedDecimal::money($installment->amount) }}</p>
                                <p class="py-2 text-sm text-slate-600">Paga em {{ $installment->paid_at->format('d/m/Y') }} (vencia em {{ $installment->due_date->format('d/m/Y') }})</p>
                            </div>
                        @else
                            <x-form.input :name="'due_dates['.$installment->id.']'" :label="'Parcela '.$installment->number.' · '.LocalizedDecimal::money($installment->amount)"
                                          type="date" required :value="$installment->due_date->format('Y-m-d')" />
                        @endif
                    @endforeach
                </div>
            </section>

            <div class="flex flex-wrap items-center justify-end gap-3">
                <a href="{{ route('finance.index', ['mes' => $entry->issued_on->format('Y-m')]) }}" class="btn btn-secondary">Cancelar</a>
                <button type="submit" class="btn btn-primary">Salvar alterações</button>
            </div>
        </form>
    </div>
</x-layouts.app>
