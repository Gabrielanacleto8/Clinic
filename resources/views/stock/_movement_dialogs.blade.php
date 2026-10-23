@php
    use App\Enums\StockMovementType;
    use App\Support\LocalizedDecimal;

    $unit = $product->unit->symbol();
    $fractioned = $product->isFractioned();
    // Fracionado: a tela pede unidades fechadas (frascos) e o servidor converte para a unidade de medida.
    $content = $fractioned ? LocalizedDecimal::quantityInput($product->unit_content) : null;
    $unitsHint = $fractioned
        ? 'Cada unidade tem '.LocalizedDecimal::quantity($product->unit_content).' '.$unit.'. Só o que está fechado sai por aqui: o que resta em unidade aberta sai pelo descarte da unidade.'
        : null;
    $inputmode = $fractioned ? 'numeric' : 'decimal';
    $url = route('products.movements.store', $product);
    $user = auth()->user();
@endphp
{{-- Diálogos de saída, perda e ajuste sobre os lotes de $product. Espera
     $batchOptions (id do lote => rótulo). Quem abre escolhe o lote por
     data-dialog-values, e no ajuste manda também "expected": o saldo fechado
     que a tela mostrava. Os formulários são limpos a cada abertura (data-dialog-reset). --}}
@if ($user->can('move', $product))
    <x-dialog id="stock-exit" title="Saída de estoque">
        <form method="POST" action="{{ $url }}" class="space-y-4" novalidate data-dialog-reset>
            @csrf
            <input type="hidden" name="_dialog" value="stock-exit">
            <input type="hidden" name="type" value="{{ StockMovementType::Exit->value }}">
            @if ($fractioned)
                <input type="hidden" name="quantity_in_units" value="1">
            @endif

            <x-form.select scope="stock-exit" id="exit-batch" name="product_batch_id" label="Lote" :options="$batchOptions" required placeholder="Selecione" />
            <x-form.input scope="stock-exit" id="exit-quantity" name="quantity" :label="$fractioned ? 'Unidades fechadas' : 'Quantidade ('.$unit.')'" required autocomplete="off"
                          :hint="$unitsHint" :inputmode="$inputmode" :data-unit-content="$content" :data-unit-symbol="$fractioned ? $unit : null" />
            @if ($fractioned)
                <output for="exit-quantity" class="-mt-2 block text-xs text-slate-500" data-unit-preview></output>
            @endif
            <x-form.input scope="stock-exit" id="exit-reason" name="reason" label="Motivo" required maxlength="255" autocomplete="off" placeholder="Ex.: devolução ao fornecedor, uso interno"
                          hint="O uso em atendimentos é baixado automaticamente ao registrar o atendimento." />

            <div class="flex justify-end gap-2 pt-2">
                <button type="button" class="btn btn-secondary" data-dialog-close>Cancelar</button>
                <button type="submit" class="btn btn-primary">Registrar saída</button>
            </div>
        </form>
    </x-dialog>

    <x-dialog id="stock-loss" title="Perda de estoque">
        <form method="POST" action="{{ $url }}" class="space-y-4" novalidate data-dialog-reset>
            @csrf
            <input type="hidden" name="_dialog" value="stock-loss">
            <input type="hidden" name="type" value="{{ StockMovementType::Loss->value }}">
            @if ($fractioned)
                <input type="hidden" name="quantity_in_units" value="1">
            @endif

            <x-form.select scope="stock-loss" id="loss-batch" name="product_batch_id" label="Lote" :options="$batchOptions" required placeholder="Selecione" />
            <x-form.input scope="stock-loss" id="loss-quantity" name="quantity" :label="$fractioned ? 'Unidades fechadas perdidas' : 'Quantidade perdida ('.$unit.')'" required autocomplete="off"
                          :hint="$unitsHint" :inputmode="$inputmode" :data-unit-content="$content" :data-unit-symbol="$fractioned ? $unit : null" />
            @if ($fractioned)
                <output for="loss-quantity" class="-mt-2 block text-xs text-slate-500" data-unit-preview></output>
            @endif
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
        <form method="POST" action="{{ $url }}" class="space-y-4" novalidate data-dialog-reset>
            @csrf
            <input type="hidden" name="_dialog" value="stock-adjustment">
            <input type="hidden" name="type" value="{{ StockMovementType::Adjustment->value }}">
            {{-- Saldo fechado que a tela mostrava: se o lote se mover até o envio, o ajuste é recusado. --}}
            <input type="hidden" name="expected" value="">
            @if ($fractioned)
                <input type="hidden" name="quantity_in_units" value="1">
            @endif

            <x-form.select scope="stock-adjustment" id="adjustment-batch" name="product_batch_id" label="Lote" :options="$batchOptions" required placeholder="Selecione" />
            <x-form.input scope="stock-adjustment" id="adjustment-quantity" name="quantity"
                          :label="$fractioned ? 'Unidades fechadas contadas' : 'Quantidade contada ('.$unit.')'" required autocomplete="off"
                          :hint="$fractioned
                              ? 'Conte só as unidades fechadas (cada uma tem '.LocalizedDecimal::quantity($product->unit_content).' '.$unit.'). As unidades abertas ficam como estão. Lote vencido não se ajusta: baixe-o como perda.'
                              : 'Informe o saldo real do lote. O sistema calcula e registra a diferença. Lote vencido não se ajusta: baixe-o como perda.'"
                          :inputmode="$inputmode" :data-unit-content="$content" :data-unit-symbol="$fractioned ? $unit : null" />
            @if ($fractioned)
                <output for="adjustment-quantity" class="-mt-2 block text-xs text-slate-500" data-unit-preview></output>
            @endif
            <x-form.input scope="stock-adjustment" id="adjustment-reason" name="reason" label="Motivo" required maxlength="255" autocomplete="off" placeholder="Ex.: conferência de inventário" />

            <div class="flex justify-end gap-2 pt-2">
                <button type="button" class="btn btn-secondary" data-dialog-close>Cancelar</button>
                <button type="submit" class="btn btn-primary">Registrar ajuste</button>
            </div>
        </form>
    </x-dialog>
@endif
