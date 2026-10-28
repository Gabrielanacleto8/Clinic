@php
    use App\Support\LocalizedDecimal;

    // Na listagem do estoque o formulário abre em um diálogo ($scope é o id dele).
    $scope ??= null;
    $inScope = $scope === null || old('_dialog') === $scope;
    // Dois diálogos na mesma página (cadastro e edição) não podem repetir ids.
    $id = fn (string $field) => $scope ? $scope.'-'.$field : $field;
@endphp
@csrf

<section @class(['card p-6' => ! $scope])>
    <div class="grid grid-cols-1 gap-4 md:grid-cols-6">
        <x-form.input :scope="$scope" class="md:col-span-4" :id="$id('name')" name="name" label="Nome" :value="$product->name" required maxlength="150" autocomplete="off" />
        <x-form.input :scope="$scope" class="md:col-span-2" :id="$id('code')" name="code" label="Código" :value="$product->code" maxlength="40" autocomplete="off"
                      hint="Código interno ou de barras." />

        <x-form.select :scope="$scope" class="md:col-span-2" :id="$id('category')" name="category" label="Categoria" :options="App\Enums\ProductCategory::options()"
                       :selected="$product->category?->value" required placeholder="Selecione" />
        <x-form.input :scope="$scope" class="md:col-span-2" :id="$id('brand')" name="brand" label="Marca" :value="$product->brand" maxlength="100" autocomplete="off" />
        <x-form.input :scope="$scope" class="md:col-span-2" :id="$id('manufacturer')" name="manufacturer" label="Fabricante" :value="$product->manufacturer" maxlength="100" autocomplete="off" />

        <x-form.input :scope="$scope" class="md:col-span-2" :id="$id('supplier')" name="supplier" label="Fornecedor" :value="$product->supplier" maxlength="150" autocomplete="off" />
        <x-form.select :scope="$scope" class="md:col-span-2" :id="$id('unit')" name="unit" label="Unidade de medida" :options="App\Enums\ProductUnit::options()"
                       :selected="$product->unit?->value" required placeholder="Selecione" />
        <x-form.input :scope="$scope" class="md:col-span-2" :id="$id('minimum_stock')" name="minimum_stock" label="Estoque mínimo" :value="LocalizedDecimal::quantityInput($product->minimum_stock ?? 0)"
                      required inputmode="decimal" autocomplete="off" hint="Zero desliga o alerta de estoque baixo." />

        {{-- Preço de venda não é usado em nenhuma tela: fica fora do formulário até ter uso. --}}
        <x-form.input :scope="$scope" class="md:col-span-4" :id="$id('cost')" name="cost" label="Custo de referência (R$)" :value="LocalizedDecimal::unitMoneyInput($product->cost)"
                      inputmode="decimal" autocomplete="off" placeholder="0,00"
                      hint="Por unidade de medida, usado só quando a entrada não informa custo. Nas baixas vale o custo médio das entradas do lote." />
        <div class="md:col-span-2 md:pt-7">
            <label class="flex items-center gap-2 text-sm">
                <input type="hidden" name="is_active" value="0">
                <input type="checkbox" name="is_active" value="1" class="size-4 rounded border-slate-300 accent-brand"
                       @checked($inScope ? old('is_active', $product->is_active) : $product->is_active)>
                Ativo
            </label>
            @if ($inScope && $errors->has('is_active'))
                <p class="mt-1 text-sm text-red-700">{{ $errors->first('is_active') }}</p>
                {{-- Só aparece ao desativar produto com saldo: um clique errado não tira o produto do atendimento sem aviso. --}}
                <label class="mt-2 flex items-start gap-2 text-sm">
                    <input type="hidden" name="confirm_deactivate" value="0">
                    <input type="checkbox" name="confirm_deactivate" value="1" class="mt-0.5 size-4 rounded border-slate-300 accent-brand" @checked(old('confirm_deactivate'))>
                    Confirmo a desativação
                </label>
            @endif
        </div>
    </div>

    <fieldset class="mt-6 border-t border-slate-200 pt-5">
        <legend class="text-sm font-semibold text-slate-700">Produto fracionado</legend>
        <p class="mt-1 text-sm text-slate-600">
            Para frasco, seringa ou ampola usados aos poucos. Cada unidade é aberta e controlada à parte. Deixe em branco se o produto sai inteiro do lote.
        </p>
        <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-6">
            <x-form.input :scope="$scope" class="md:col-span-2" :id="$id('unit_content')" name="unit_content" label="Conteúdo de cada unidade" :value="LocalizedDecimal::quantityInput($product->unit_content)"
                          inputmode="decimal" autocomplete="off" hint="Na unidade de medida. Ex.: 100 (U) por frasco." />
            <x-form.input :scope="$scope" class="md:col-span-2" :id="$id('hours_after_opening')" name="hours_after_opening" label="Prazo depois de aberto (horas)" :value="$product->hours_after_opening"
                          type="number" min="1" max="8760" hint="Pela bula. Vazio: vale só a validade do lote." />
            <div class="md:col-span-2 md:pt-7">
                <label class="flex items-start gap-2 text-sm">
                    <input type="hidden" name="multi_patient_use" value="0">
                    <input type="checkbox" name="multi_patient_use" value="1" class="mt-0.5 size-4 rounded border-slate-300 accent-brand"
                           @checked($inScope ? old('multi_patient_use', $product->multi_patient_use) : $product->multi_patient_use)>
                    A sobra pode ser usada em outro paciente
                </label>
                <p class="mt-1 text-xs text-slate-500">Marque só se o fabricante e as normas sanitárias permitem. Sem isso, a sobra é descartada ao fim do atendimento.</p>
            </div>
        </div>
    </fieldset>
    <p class="mt-4 text-xs text-slate-500">Quantidade, lote e validade são informados nas entradas de estoque.</p>
</section>

<div class="flex flex-wrap items-center justify-end gap-3">
    @if ($scope)
        <button type="button" class="btn btn-secondary" data-dialog-close>Cancelar</button>
    @else
        <a href="{{ $product->exists ? route('products.show', $product) : route('products.index') }}" class="btn btn-secondary">Cancelar</a>
    @endif
    <button type="submit" class="btn btn-primary">{{ $submitLabel ?? ($product->exists ? 'Salvar alterações' : 'Cadastrar produto') }}</button>
</div>
