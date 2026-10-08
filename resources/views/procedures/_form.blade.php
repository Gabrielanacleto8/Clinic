@csrf

<section class="card p-6">
    <div class="grid grid-cols-1 gap-4 md:grid-cols-6">
        <x-form.input class="md:col-span-4" name="name" label="Nome" :value="$procedure->name" required maxlength="150" autocomplete="off" />
        <x-form.select class="md:col-span-2" name="category" label="Categoria" :options="App\Enums\ProcedureCategory::options()"
                       :selected="$procedure->category?->value" required placeholder="Selecione" />
        <x-form.input class="md:col-span-2" name="price" label="Valor (R$)" :value="App\Support\LocalizedDecimal::moneyInput($procedure->price)"
                      required inputmode="decimal" autocomplete="off" placeholder="0,00" />
        <x-form.select class="md:col-span-2" name="duration_minutes" label="Duração estimada" :options="App\Support\Duration::options()"
                       :selected="$procedure->duration_minutes" required />
        <div class="md:col-span-2 md:pt-7">
            <label class="flex items-center gap-2 text-sm">
                <input type="hidden" name="is_active" value="0">
                <input type="checkbox" name="is_active" value="1" class="size-4 rounded border-slate-300 accent-teal-700"
                       @checked(old('is_active', $procedure->is_active))>
                Ativo
            </label>
            <p class="mt-1 text-xs text-slate-500">Inativos não aparecem em novos agendamentos e atendimentos.</p>
        </div>
        <x-form.textarea class="md:col-span-6" name="description" label="Descrição" :value="$procedure->description" rows="3" maxlength="2000" />
    </div>
</section>

@php
    use App\Support\LocalizedDecimal;

    // Linhas já salvas mais algumas em branco, dentro do limite aceito.
    $savedProducts = old('products', $procedure->exists
        ? $procedure->products->map(fn ($product) => ['product_id' => $product->id, 'quantity' => LocalizedDecimal::quantity($product->pivot->quantity)])->all()
        : []);
    $productRows = min(App\Http\Requests\ProcedureRequest::MAX_PRODUCTS, max(3, count($savedProducts) + 2));
@endphp
<section class="card p-6">
    <h2 class="text-sm font-semibold tracking-wide text-slate-500 uppercase">Produtos normalmente utilizados</h2>
    <p class="mt-1 text-sm text-slate-600">
        Servem de sugestão ao registrar um atendimento deste procedimento. A baixa no estoque usa o que for confirmado no atendimento.
    </p>
    @error('products')
        <p class="mt-2 text-sm text-red-700">{{ $message }}</p>
    @enderror

    @if ($products->isEmpty())
        <p class="mt-4 text-sm text-slate-600">Nenhum produto ativo no estoque ainda.</p>
    @else
        <div class="mt-4 space-y-3">
            @for ($i = 0; $i < $productRows; $i++)
                @php $row = $savedProducts[$i] ?? []; @endphp
                <fieldset class="grid grid-cols-3 gap-3">
                    <legend class="sr-only">Produto {{ $i + 1 }}</legend>
                    <div class="col-span-2">
                        <label for="products-{{ $i }}-product_id" class="label @if ($i > 0) sr-only @endif">Produto</label>
                        <select id="products-{{ $i }}-product_id" name="products[{{ $i }}][product_id]"
                                @class(['input', 'input-error' => $errors->has("products.$i.product_id")])>
                            <option value="">—</option>
                            @foreach ($products as $product)
                                <option value="{{ $product->id }}" @selected((string) ($row['product_id'] ?? '') === (string) $product->id)>
                                    {{ $product->name }} ({{ $product->unit->symbol() }})
                                </option>
                            @endforeach
                        </select>
                        @error("products.$i.product_id")
                            <p class="mt-1 text-sm text-red-700">{{ $message }}</p>
                        @enderror
                    </div>
                    <div>
                        <label for="products-{{ $i }}-quantity" class="label @if ($i > 0) sr-only @endif">Quantidade estimada</label>
                        <input id="products-{{ $i }}-quantity" name="products[{{ $i }}][quantity]" type="text" inputmode="decimal" autocomplete="off"
                               value="{{ $row['quantity'] ?? '' }}" @class(['input', 'input-error' => $errors->has("products.$i.quantity")])>
                        @error("products.$i.quantity")
                            <p class="mt-1 text-sm text-red-700">{{ $message }}</p>
                        @enderror
                    </div>
                </fieldset>
            @endfor
        </div>
    @endif
</section>

<div class="flex flex-wrap items-center justify-end gap-3">
    <a href="{{ route('procedures.index') }}" class="btn btn-secondary">Cancelar</a>
    <button type="submit" class="btn btn-primary">{{ $procedure->exists ? 'Salvar alterações' : 'Cadastrar procedimento' }}</button>
</div>
