@php
    use App\Enums\ProductCategory;
    use App\Enums\ProductUnit;
    use App\Support\ClinicTime;
    use App\Support\LocalizedDecimal;
    use Carbon\CarbonImmutable;

    $date = fn (?string $day) => $day ? CarbonImmutable::parse($day)->format('d/m/Y') : null;
    $productOptions = $products->mapWithKeys(fn ($product) => [
        $product->id => $product->name.($product->code ? ' · '.$product->code : '').' ('.$product->unit->symbol()
            .($product->isFractioned() ? ', unidade de '.LocalizedDecimal::quantityInput($product->unit_content).' '.$product->unit->symbol() : '').')',
    ])->all();
    $checked = fn (string $key, bool $default) => (string) old($key, $default ? '1' : '0') === '1';
    $heading = 'text-xs font-semibold tracking-wide text-slate-500 uppercase';
@endphp
<x-layouts.app title="Importar nota fiscal">
    <div>
        <a href="{{ route('products.index') }}" class="text-sm text-slate-600 hover:underline">← Estoque</a>
        <h1 class="mt-1 text-xl font-semibold tracking-tight">Importar nota fiscal</h1>
        <p class="mt-1 text-sm text-slate-600">
            {{ implode(' · ', array_filter([
                $invoice['number'] !== '' ? 'NF nº '.$invoice['number'] : null,
                $invoice['series'] !== '' ? 'série '.$invoice['series'] : null,
                $invoice['issued_on'] ? 'emitida em '.$date($invoice['issued_on']) : null,
                $invoice['supplier'] !== '' ? $invoice['supplier'] : null,
            ])) }}
        </p>
        <p class="mt-2 max-w-3xl text-sm text-slate-600">
            Confira cada item antes de confirmar. A quantidade vem na unidade da nota; em produto fracionado já cadastrado ela aparece convertida
            para a unidade do estoque (2 frascos de 100 U viram 200 U). O custo por unidade do estoque é o valor total do item dividido pela quantidade lançada.
            Nada é gravado até você confirmar.
        </p>
    </div>

    @if ($imported)
        <p class="mt-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900" role="alert">
            Esta nota fiscal já foi importada em {{ ClinicTime::format($imported->created_at) }}{{ $imported->user ? ' por '.$imported->user->name : '' }}. Ela não pode ser lançada de novo.
        </p>
    @elseif ($alreadyImported)
        <p class="mt-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900" role="alert">
            Já existem entradas lançadas com a nota nº {{ $invoice['number'] }} deste fornecedor. Confirmar de novo vai somar as quantidades outra vez.
        </p>
    @endif

    @foreach (['items', 'importacao'] as $field)
        @error($field)
            <p class="mt-4 text-sm text-red-700" role="alert">{{ $message }}</p>
        @enderror
    @endforeach

    <form method="POST" action="{{ route('stock.invoice.update') }}" class="mt-6 space-y-4" novalidate data-keepalive="{{ route('session.keepalive') }}">
        @csrf
        @method('PUT')
        <input type="hidden" name="importacao" value="{{ $token }}">

        @foreach ($invoice['items'] as $index => $item)
            @php
                $name = fn (string $field) => "items[{$index}][{$field}]";
                $id = fn (string $field) => "item-{$index}-{$field}";
                $match = $matches[$index] ?? null;
                $hasLots = collect($item['lots'])->every(fn (array $lot) => $lot['batch_code'] !== '');
            @endphp
            <section class="card p-4 sm:p-6" aria-labelledby="{{ $id('titulo') }}">
                <div class="flex flex-wrap items-start justify-between gap-x-6 gap-y-2">
                    <div class="min-w-0">
                        <h2 id="{{ $id('titulo') }}" class="font-medium break-words">{{ $index + 1 }}. {{ $item['name'] }}</h2>
                        <p class="mt-0.5 text-xs text-slate-500">
                            Na nota: {{ LocalizedDecimal::quantity($item['quantity']) }} {{ $item['invoice_unit'] }} × {{ LocalizedDecimal::unitMoney($item['unit_cost']) }}
                            = {{ LocalizedDecimal::money($item['total_cost']) }}
                        </p>
                    </div>
                    <label class="flex items-center gap-2 text-sm font-medium">
                        <input type="hidden" name="{{ $name('import') }}" value="0">
                        <input type="checkbox" name="{{ $name('import') }}" value="1" class="size-4 rounded border-slate-300 accent-teal-700" @checked($checked("items.{$index}.import", true))>
                        Importar este item
                    </label>
                </div>

                <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-6" data-invoice-item>
                    <x-form.select class="md:col-span-6" :id="$id('product')" :name="$name('product_id')" label="Produto no estoque" :options="$productOptions" :selected="$match"
                                   placeholder="Cadastrar como produto novo" data-invoice-product />
                    @if ($match)
                        <p class="-mt-2 text-xs text-teal-800 md:col-span-6">
                            Este item já está cadastrado: a entrada vai para o produto acima.
                            @if ($item['converted'] ?? false)
                                A quantidade dos lotes já está na unidade do estoque (frascos × conteúdo de cada um).
                            @endif
                        </p>
                    @elseif (isset($inactiveMatches[$index]))
                        <p class="-mt-2 text-xs text-amber-800 md:col-span-6">
                            Já existe o produto inativo "{{ $inactiveMatches[$index] }}" com este nome ou código. Para lançar a entrada nele, reative-o no cadastro do estoque e envie a nota de novo;
                            para cadastrar outro produto, mude o nome e o código abaixo.
                        </p>
                    @endif

                    <x-form.input class="md:col-span-2" :id="$id('cost')" :name="$name('total_cost')" label="Valor total do item (R$)" :value="LocalizedDecimal::moneyInput($item['total_cost'])"
                                  inputmode="decimal" autocomplete="off" hint="Dele sai o custo por unidade do estoque." />

                    {{-- Só vale quando o item vira produto novo: app.js esconde o bloco quando um produto é escolhido. --}}
                    <div class="grid grid-cols-1 gap-4 md:col-span-6 md:grid-cols-6" data-invoice-new-product>
                        <p class="{{ $heading }} md:col-span-6">Cadastro do produto novo</p>
                        <x-form.input class="md:col-span-4" :id="$id('name')" :name="$name('name')" label="Nome" :value="$item['name']" maxlength="150" autocomplete="off" />
                        <x-form.input class="md:col-span-2" :id="$id('code')" :name="$name('code')" label="Código" :value="$item['code']" maxlength="40" autocomplete="off" />
                        <x-form.select class="md:col-span-3" :id="$id('category')" :name="$name('category')" label="Categoria" :options="ProductCategory::options()" :selected="$item['category']" />
                        <x-form.select class="md:col-span-3" :id="$id('unit')" :name="$name('unit')" label="Unidade de medida do estoque" :options="ProductUnit::options()" :selected="$item['unit']" />

                        <p class="text-xs text-slate-600 md:col-span-6">
                            <span class="font-medium">Usado aos poucos (fracionado)?</span>
                            Frasco, seringa ou ampola de toxina, preenchedor etc.: escolha acima a unidade do conteúdo (U, mL) e informe o conteúdo de cada unidade.
                            A quantidade dos lotes abaixo fica em unidades fechadas da nota, e o sistema multiplica pelo conteúdo ({{ LocalizedDecimal::quantity($item['quantity']) }} {{ $item['invoice_unit'] }} × conteúdo).
                        </p>
                        <x-form.input class="md:col-span-2" :id="$id('content')" :name="$name('unit_content')" label="Conteúdo de cada unidade" inputmode="decimal" autocomplete="off"
                                      hint="Na unidade escolhida. Ex.: 100 (U) por frasco. Em branco: não fracionado." />
                        <x-form.input class="md:col-span-2" :id="$id('hours')" :name="$name('hours_after_opening')" label="Prazo depois de aberto (horas)" type="number" min="1" max="8760"
                                      hint="Pela bula. Vazio: vale só a validade do lote." />
                        <div class="md:col-span-2 md:pt-7">
                            <label class="flex items-start gap-2 text-sm">
                                <input type="hidden" name="{{ $name('multi_patient_use') }}" value="0">
                                <input type="checkbox" name="{{ $name('multi_patient_use') }}" value="1" class="mt-0.5 size-4 rounded border-slate-300 accent-teal-700" @checked($checked("items.{$index}.multi_patient_use", false))>
                                A sobra pode ser usada em outro paciente
                            </label>
                        </div>
                    </div>
                </div>

                <div class="mt-5 border-t border-slate-100 pt-4">
                    <label class="flex items-center gap-2 text-sm font-medium">
                        <input type="hidden" name="{{ $name('enter') }}" value="0">
                        <input type="checkbox" name="{{ $name('enter') }}" value="1" class="size-4 rounded border-slate-300 accent-teal-700" @checked($checked("items.{$index}.enter", $hasLots))>
                        Lançar entrada de estoque
                    </label>
                    @unless ($hasLots)
                        <p class="mt-1 text-xs text-slate-500">A nota não informa o lote deste item. Digite o lote e marque a opção acima para lançar a entrada.</p>
                    @endunless

                    @foreach ($item['lots'] as $lotIndex => $lot)
                        @php
                            $lotName = fn (string $field) => "items[{$index}][lots][{$lotIndex}][{$field}]";
                            $lotId = fn (string $field) => "item-{$index}-lot-{$lotIndex}-{$field}";
                        @endphp
                        <div class="mt-3 grid grid-cols-2 gap-4 md:grid-cols-4">
                            <x-form.input :id="$lotId('batch')" :name="$lotName('batch_code')" label="Lote" :value="$lot['batch_code']" maxlength="60" autocomplete="off" />
                            <x-form.input :id="$lotId('quantity')" :name="$lotName('quantity')" label="Quantidade" :value="LocalizedDecimal::quantityInput($lot['quantity'])" inputmode="decimal" autocomplete="off" />
                            <x-form.input :id="$lotId('manufactured')" :name="$lotName('manufactured_on')" label="Fabricação" type="date" :value="$lot['manufactured_on']" :max="ClinicTime::today()" />
                            <x-form.input :id="$lotId('expires')" :name="$lotName('expires_on')" label="Validade" type="date" :value="$lot['expires_on']" />
                        </div>
                    @endforeach
                </div>
            </section>
        @endforeach

        <div class="flex flex-wrap items-center justify-end gap-3">
            <button type="submit" form="cancelar-importacao" class="btn btn-secondary">Cancelar importação</button>
            @unless ($imported)
                <button type="submit" class="btn btn-primary">Confirmar importação</button>
            @endunless
        </div>
    </form>

    <form id="cancelar-importacao" method="POST" action="{{ route('stock.invoice.destroy') }}">
        @csrf
        @method('DELETE')
        <input type="hidden" name="importacao" value="{{ $token }}">
    </form>
</x-layouts.app>
