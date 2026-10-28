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
                <input type="checkbox" name="is_active" value="1" class="size-4 rounded border-slate-300 accent-brand"
                       @checked(old('is_active', $procedure->is_active))>
                Ativo
            </label>
            <p class="mt-1 text-xs text-slate-500">Inativos não aparecem em novos agendamentos e atendimentos.</p>
            @if (($openAppointments ?? 0) > 0)
                <p class="mt-1 text-xs text-amber-800">Há {{ $openAppointments }} {{ $openAppointments === 1 ? 'agendamento em aberto' : 'agendamentos em aberto' }} com este procedimento. Desativar não os cancela: eles continuam válidos e podem ser registrados.</p>
            @endif
        </div>
        <x-form.textarea class="md:col-span-6" name="description" label="Descrição" :value="$procedure->description" rows="3" maxlength="2000" />
    </div>
</section>

<section class="card p-6">
    <h2 class="text-sm font-semibold tracking-wide text-slate-500 uppercase">Termo de consentimento</h2>
    <p class="mt-1 text-sm text-slate-600">
        Impresso pela ficha do paciente (Mais › Termo de consentimento) para assinar antes do procedimento. Em branco, vale o modelo padrão.
        Revise o texto com a assessoria jurídica da clínica.
    </p>
    <p class="mt-2 text-xs text-slate-500">
        Marcações trocadas na impressão:
        @foreach (App\Support\ConsentTerm::TOKENS as $token => $meaning)
            <code class="rounded bg-slate-100 px-1">{{ $token }}</code> {{ $meaning }}@if (! $loop->last), @endif
        @endforeach
    </p>
    <x-form.textarea class="mt-4" name="consent_template" label="Texto do termo" :value="$procedure->consent_template" rows="10" maxlength="20000"
                     :placeholder="App\Support\ConsentTerm::DEFAULT_TEMPLATE" />
</section>

@php
    use App\Support\LocalizedDecimal;

    // Linhas já salvas mais algumas em branco, dentro do limite aceito.
    $savedProducts = old('products', $procedure->exists
        ? $procedure->products->map(fn ($product) => ['product_id' => $product->id, 'quantity' => LocalizedDecimal::quantityInput($product->pivot->quantity)])->all()
        : []);
    $productRows = min(App\Http\Requests\ProcedureRequest::MAX_PRODUCTS, max(3, count($savedProducts) + 2));
@endphp
<section class="card p-6">
    <h2 class="text-sm font-semibold tracking-wide text-slate-500 uppercase">Produtos normalmente utilizados</h2>
    <p class="mt-1 text-sm text-slate-600">
        Servem de sugestão ao registrar um atendimento deste procedimento, com a quantidade estimada na unidade de cada produto. A baixa no estoque usa o que for confirmado no atendimento.
    </p>
    @error('products')
        <p class="mt-2 text-sm text-red-700">{{ $message }}</p>
    @enderror

    @if ($products->isEmpty())
        <p class="mt-4 text-sm text-slate-600">Nenhum produto ativo no estoque ainda.</p>
    @else
        <div id="procedure-products" class="mt-4 space-y-3">
            @for ($i = 0; $i < $productRows; $i++)
                @php $row = $savedProducts[$i] ?? []; @endphp
                {{-- Produto ocupa o que sobra; a quantidade tem largura fixa e estreita. Os
                     rótulos ficam numa linha só, para os campos das duas colunas alinharem. --}}
                <fieldset class="flex items-start gap-3">
                    <legend class="sr-only">Produto {{ $i + 1 }}</legend>
                    <div class="min-w-0 flex-1">
                        <label for="products-{{ $i }}-product_id" class="label @if ($i > 0) sr-only @endif">Produto</label>
                        <select id="products-{{ $i }}-product_id" name="products[{{ $i }}][product_id]"
                                @class(['input', 'input-error' => $errors->has("products.$i.product_id")])>
                            <option value="">—</option>
                            @foreach ($products as $product)
                                <option value="{{ $product->id }}" @selected((string) ($row['product_id'] ?? '') === (string) $product->id)>
                                    {{ $product->name }} ({{ $product->unit->symbol() }}){{ $product->is_active ? '' : ' · inativo' }}
                                </option>
                            @endforeach
                        </select>
                        @error("products.$i.product_id")
                            <p class="mt-1 text-sm text-red-700">{{ $message }}</p>
                        @enderror
                    </div>
                    <div class="w-24 shrink-0 sm:w-28">
                        <label for="products-{{ $i }}-quantity" class="label whitespace-nowrap @if ($i > 0) sr-only @endif">Quantidade</label>
                        <input id="products-{{ $i }}-quantity" name="products[{{ $i }}][quantity]" type="text" inputmode="decimal" autocomplete="off"
                               value="{{ $row['quantity'] ?? '' }}" @class(['input text-right tabular-nums', 'input-error' => $errors->has("products.$i.quantity")])>
                        @error("products.$i.quantity")
                            <p class="mt-1 text-sm text-red-700">{{ $message }}</p>
                        @enderror
                    </div>
                </fieldset>
            @endfor
        </div>
        <button type="button" class="btn btn-secondary mt-3" data-rows-add="procedure-products" data-rows-max="{{ App\Http\Requests\ProcedureRequest::MAX_PRODUCTS }}"
                @disabled($productRows >= App\Http\Requests\ProcedureRequest::MAX_PRODUCTS)>Adicionar produto</button>
    @endif
</section>

<div class="flex flex-wrap items-center justify-end gap-3">
    <a href="{{ route('procedures.index') }}" class="btn btn-secondary">Cancelar</a>
    <button type="submit" class="btn btn-primary">{{ $procedure->exists ? 'Salvar alterações' : 'Cadastrar procedimento' }}</button>
</div>
