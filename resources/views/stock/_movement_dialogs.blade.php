@php
    use App\Enums\StockMovementType;

    $unit = $product->unit->symbol();
    // Fracionado: saída, perda e ajuste valem só para o lacrado, em unidades inteiras.
    $sealedHint = $product->isFractioned()
        ? 'Só o que está lacrado, em unidades inteiras de '.App\Support\LocalizedDecimal::quantity($product->unit_content).' '.$unit.'. O que resta em unidade aberta sai pelo descarte da unidade.'
        : null;
    $url = route('products.movements.store', $product);
    $user = auth()->user();
@endphp
{{-- Diálogos de saída, perda e ajuste sobre os lotes de $product. Espera
     $batchOptions (id do lote => rótulo). Quem abre escolhe o lote por data-dialog-values. --}}
@if ($user->can('move', $product))
    <x-dialog id="stock-exit" title="Saída de estoque">
        <form method="POST" action="{{ $url }}" class="space-y-4" novalidate>
            @csrf
            <input type="hidden" name="_dialog" value="stock-exit">
            <input type="hidden" name="type" value="{{ StockMovementType::Exit->value }}">

            <x-form.select scope="stock-exit" id="exit-batch" name="product_batch_id" label="Lote" :options="$batchOptions" required placeholder="Selecione" />
            <x-form.input scope="stock-exit" id="exit-quantity" name="quantity" :label="'Quantidade ('.$unit.')'" required inputmode="decimal" autocomplete="off" :hint="$sealedHint" />
            <x-form.input scope="stock-exit" id="exit-reason" name="reason" label="Motivo" required maxlength="255" autocomplete="off" placeholder="Ex.: devolução ao fornecedor, uso interno"
                          hint="O uso em atendimentos é baixado automaticamente ao registrar o atendimento." />

            <div class="flex justify-end gap-2 pt-2">
                <button type="button" class="btn btn-secondary" data-dialog-close>Cancelar</button>
                <button type="submit" class="btn btn-primary">Registrar saída</button>
            </div>
        </form>
    </x-dialog>

    <x-dialog id="stock-loss" title="Perda de estoque">
        <form method="POST" action="{{ $url }}" class="space-y-4" novalidate>
            @csrf
            <input type="hidden" name="_dialog" value="stock-loss">
            <input type="hidden" name="type" value="{{ StockMovementType::Loss->value }}">

            <x-form.select scope="stock-loss" id="loss-batch" name="product_batch_id" label="Lote" :options="$batchOptions" required placeholder="Selecione" />
            <x-form.input scope="stock-loss" id="loss-quantity" name="quantity" :label="'Quantidade perdida ('.$unit.')'" required inputmode="decimal" autocomplete="off" :hint="$sealedHint" />
            <x-form.input scope="stock-loss" id="loss-reason" name="reason" label="Motivo" required maxlength="255" autocomplete="off" placeholder="Ex.: quebra, extravio, descarte por vencimento" />

            <div class="flex justify-end gap-2 pt-2">
                <button type="button" class="btn btn-secondary" data-dialog-close>Cancelar</button>
                <button type="submit" class="btn btn-primary">Registrar perda</button>
            </div>
        </form>
    </x-dialog>
@endif

@if ($user->can('adjust', $product))
    <x-dialog id="stock-adjustment" title="Ajuste de estoque">
        <form method="POST" action="{{ $url }}" class="space-y-4" novalidate>
            @csrf
            <input type="hidden" name="_dialog" value="stock-adjustment">
            <input type="hidden" name="type" value="{{ StockMovementType::Adjustment->value }}">

            <x-form.select scope="stock-adjustment" id="adjustment-batch" name="product_batch_id" label="Lote" :options="$batchOptions" required placeholder="Selecione" />
            <x-form.input scope="stock-adjustment" id="adjustment-quantity" name="quantity"
                          :label="($product->isFractioned() ? 'Quantidade lacrada contada (' : 'Quantidade contada (').$unit.')'" required inputmode="decimal" autocomplete="off"
                          :hint="$product->isFractioned()
                              ? 'Conte só as unidades lacradas, em unidades inteiras de '.App\Support\LocalizedDecimal::quantity($product->unit_content).' '.$unit.'. As unidades abertas ficam como estão. Lote vencido não se ajusta: registre a perda.'
                              : 'Informe o saldo real do lote. O sistema calcula e registra a diferença. Lote vencido não se ajusta: registre a perda.'" />
            <x-form.input scope="stock-adjustment" id="adjustment-reason" name="reason" label="Motivo" required maxlength="255" autocomplete="off" placeholder="Ex.: conferência de inventário" />

            <div class="flex justify-end gap-2 pt-2">
                <button type="button" class="btn btn-secondary" data-dialog-close>Cancelar</button>
                <button type="submit" class="btn btn-primary">Registrar ajuste</button>
            </div>
        </form>
    </x-dialog>
@endif
