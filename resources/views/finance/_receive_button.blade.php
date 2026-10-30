@php
    use App\Support\LocalizedDecimal;

    // Botão que abre o diálogo de recebimento de uma parcela em aberto.
    // Espera $installment com entry (installments_count, total_amount) carregado.
    $entry = $installment->entry;
    $isIncome = $entry->type === App\Enums\FinancialEntryType::Income;
    $parcel = $entry->installments_count > 1 ? 'Parcela '.$installment->number.'/'.$entry->installments_count : 'À vista';
    $allAtOnce = $entry->payment_method->settlesAllInstallments($entry->type) && $entry->installments_count > 1;
    // No cartão de crédito, receber uma parcela recebe todas: o resumo e a confirmação dizem isso.
    $summary = $allAtOnce
        ? $entry->description.' · recebe as '.$entry->installments_count.' parcelas do cartão de crédito, '.LocalizedDecimal::money($entry->total())
        : $entry->description.' · '.$parcel.' · '.LocalizedDecimal::money($installment->amount);
    $confirm = $allAtOnce ? 'Confirmar o recebimento das '.$entry->installments_count.' parcelas ('.LocalizedDecimal::money($entry->total()).')?' : '';
    $values = json_encode(['installment_id' => $installment->id, 'payment_method' => $entry->payment_method->value]);
@endphp
<button type="button" class="cursor-pointer font-medium text-slate-800 hover:underline" data-dialog-open="installment-payment"
        data-dialog-values="{{ $values }}"
        data-dialog-summary="{{ $summary }}" data-dialog-confirm="{{ $confirm }}">
    {{ $isIncome ? 'Receber' : 'Pagar' }}
</button>
