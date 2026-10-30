@php
    use App\Enums\FinancialEntryType;

    // Janela de nova despesa ou nova receita. Com erro, o envio volta para a
    // mesma tela e a janela reabre com o que foi digitado ("_dialog").
    $isIncome = $type === FinancialEntryType::Income;
    $dialogId = $isIncome ? 'entry-income' : 'entry-expense';
@endphp
<x-dialog :id="$dialogId" :title="$isIncome ? 'Nova receita' : 'Nova despesa'" size="lg">
    <form method="POST" action="{{ route('finance.entries.store') }}" class="space-y-5" novalidate>
        @csrf
        <input type="hidden" name="_dialog" value="{{ $dialogId }}">
        <input type="hidden" name="type" value="{{ $type->value }}">
        <x-form-errors :scope="$dialogId" />

        @include('finance._entry_fields', ['type' => $type, 'scope' => $dialogId, 'patient' => $isIncome ? ($entryPatient ?? null) : null])

        <div class="flex flex-wrap justify-end gap-2 border-t border-slate-200 pt-4">
            <button type="button" class="btn btn-secondary" data-dialog-close>Cancelar</button>
            <button type="submit" class="btn btn-primary">{{ $isIncome ? 'Lançar receita' : 'Lançar despesa' }}</button>
        </div>
    </form>
</x-dialog>
