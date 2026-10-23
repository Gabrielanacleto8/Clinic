@php
    use App\Enums\ProductCategory;
    use App\Support\LocalizedDecimal;

    $filtered = array_filter($filters) !== [];
@endphp
<x-layouts.app title="Inventário">
    <div>
        <a href="{{ route('stock.batches.index') }}" class="text-sm text-slate-600 hover:underline">← Lotes e validade</a>
        <h1 class="mt-1 text-xl font-semibold tracking-tight">Inventário</h1>
        <p class="mt-1 max-w-3xl text-sm text-slate-600">
            Informe a quantidade encontrada em cada lote conferido. Lote em branco fica como está, e cada diferença é registrada como ajuste no histórico de movimentações.
        </p>
        <p class="mt-1 max-w-3xl text-sm text-slate-600">
            Produto fracionado: conte os frascos, seringas ou ampolas ainda fechados. O que resta nas unidades abertas não entra na contagem.
            Lotes vencidos não aparecem aqui: baixe-os como perda na página do lote.
            Se um lote for movimentado enquanto você conta, a contagem dele é recusada para ser refeita.
        </p>
    </div>

    <form method="GET" action="{{ route('stock.inventory.edit') }}" class="mt-6 grid grid-cols-1 gap-2 sm:grid-cols-12" role="search">
        <div class="sm:col-span-6">
            <label for="q" class="sr-only">Buscar por produto ou lote</label>
            <input id="q" name="q" type="search" value="{{ $filters['q'] }}" maxlength="100" class="input" placeholder="Buscar por produto ou lote">
        </div>
        <div class="sm:col-span-4">
            <label for="categoria" class="sr-only">Categoria</label>
            <select id="categoria" name="categoria" class="input">
                <option value="">Todas as categorias</option>
                @foreach (ProductCategory::options() as $value => $label)
                    <option value="{{ $value }}" @selected($filters['categoria'] === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <button type="submit" class="btn btn-secondary sm:col-span-2">Filtrar</button>
        @foreach (['q', 'categoria'] as $field)
            @error($field)
                <p class="text-sm text-red-700 sm:col-span-12">{{ $message }}</p>
            @enderror
        @endforeach
    </form>

    @if ($batches->isEmpty())
        <p class="card mt-4 px-4 py-10 text-center text-sm text-slate-500">
            @if ($filtered)
                Nenhum lote com saldo para conferir com estes filtros.
                <a href="{{ route('stock.inventory.edit') }}" class="font-medium text-teal-800 hover:underline">Limpar filtros</a>
            @else
                Nenhum lote com saldo para conferir.
            @endif
        </p>
    @else
        {{-- Contagem longa pelo celular: a sessão é renovada enquanto se digita e sair da página com campo preenchido pede confirmação. --}}
        <form method="POST" action="{{ route('stock.inventory.update') }}" class="mt-4" novalidate
              data-keepalive="{{ route('session.keepalive') }}" data-warn-unsaved="Há contagens digitadas que ainda não foram registradas. Sair da página perde essas contagens.">
            @csrf
            @method('PUT')

            @error('counted')
                <p class="mb-3 text-sm text-red-700" role="alert">{{ $message }}</p>
            @enderror

            <div class="card relative overflow-x-auto">
                <table class="table-stack w-full min-w-3xl text-left text-sm">
                    <thead class="border-b border-slate-200 bg-slate-50 text-xs tracking-wide text-slate-500 uppercase">
                        <tr>
                            <th scope="col" class="px-4 py-3 font-medium">Produto</th>
                            <th scope="col" class="px-4 py-3 font-medium">Lote</th>
                            <th scope="col" class="px-4 py-3 font-medium">Validade</th>
                            <th scope="col" class="px-4 py-3 text-right font-medium">Saldo no sistema</th>
                            <th scope="col" class="px-4 py-3 font-medium">Contado</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($batches as $batch)
                            @php
                                $unit = $batch->product->unit->symbol();
                                $field = 'counted.'.$batch->id;
                                $fractioned = $batch->product->isFractioned();
                                $sealed = $fractioned ? $batch->sealedQuantity() : $batch->quantity;
                                $sealedUnits = $fractioned ? (int) floor((float) $sealed / (float) $batch->product->unit_content) : null;
                            @endphp
                            <tr>
                                <td data-label="Produto" class="px-4 py-3 font-medium">
                                    {{ $batch->product->name }}
                                    @if (isset($countedToday[$batch->id]))
                                        <span class="badge ml-1 bg-emerald-100 text-emerald-800">Conferido hoje</span>
                                    @endif
                                </td>
                                <td data-label="Lote" class="px-4 py-3 tabular-nums">{{ $batch->batch_code }}</td>
                                <td data-label="Validade" class="px-4 py-3 whitespace-nowrap tabular-nums">
                                    {{ $batch->expires_on?->format('d/m/Y') ?? 'Sem validade' }}
                                    @include('stock._expiry_badge', ['batch' => $batch])
                                </td>
                                <td data-label="Saldo no sistema" class="px-4 py-3 text-right whitespace-nowrap tabular-nums">
                                    @if ($fractioned)
                                        {{ $sealedUnits }} {{ $sealedUnits === 1 ? 'unidade fechada' : 'unidades fechadas' }}
                                        <span class="block text-xs text-slate-500">= {{ LocalizedDecimal::quantity($sealed) }} {{ $unit }}</span>
                                        @if ((float) $batch->open_quantity > 0)
                                            <span class="block text-xs text-slate-500">+ {{ LocalizedDecimal::quantity($batch->open_quantity) }} {{ $unit }} em unidades abertas</span>
                                        @endif
                                    @else
                                        {{ LocalizedDecimal::quantity($batch->quantity) }} {{ $unit }}
                                    @endif
                                </td>
                                <td data-label="{{ $fractioned ? 'Unidades fechadas contadas' : 'Quantidade contada' }}" class="px-4 py-2">
                                    {{-- Saldo que a tela mostrava: se ele mudar até o envio, a contagem deste lote é recusada. --}}
                                    <input type="hidden" name="expected[{{ $batch->id }}]" value="{{ LocalizedDecimal::quantityInput($sealed) }}">
                                    @if ($fractioned)
                                        <input type="hidden" name="in_units[{{ $batch->id }}]" value="1">
                                    @endif
                                    <label for="counted-{{ $batch->id }}" class="sr-only">{{ $fractioned ? 'Unidades fechadas contadas' : 'Quantidade contada' }} do lote {{ $batch->batch_code }}{{ $fractioned ? '' : ' ('.$unit.')' }}</label>
                                    <div class="flex items-center gap-2">
                                        <input id="counted-{{ $batch->id }}" name="counted[{{ $batch->id }}]" type="text" inputmode="{{ $fractioned ? 'numeric' : 'decimal' }}" autocomplete="off"
                                               value="{{ old($field) }}" @class(['input sm:max-w-32', 'input-error' => $errors->has($field)])
                                               @if ($fractioned) data-unit-content="{{ LocalizedDecimal::quantityInput($batch->product->unit_content) }}" data-unit-symbol="{{ $unit }}" @endif>
                                        @if ($fractioned)
                                            <output for="counted-{{ $batch->id }}" class="text-xs whitespace-nowrap text-slate-500" data-unit-preview></output>
                                        @else
                                            <span class="text-xs text-slate-500">{{ $unit }}</span>
                                        @endif
                                    </div>
                                    @error($field)
                                        <p class="mt-1 text-sm text-red-700">{{ $message }}</p>
                                    @enderror
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="mt-4 flex flex-wrap items-end justify-end gap-3">
                <x-form.input class="w-full sm:w-96" name="reason" label="Motivo" required maxlength="255" autocomplete="off" :value="'Inventário de '.now()->timezone(config('clinic.timezone'))->format('d/m/Y')" />
                <button type="submit" class="btn btn-primary">Registrar inventário</button>
            </div>
            @if ($batches->hasPages())
                <p class="mt-2 text-right text-xs text-slate-500">Registre esta página antes de ir para a próxima: as contagens não passam de uma página para outra.</p>
            @endif
        </form>

        <div class="mt-4">
            {{ $batches->links() }}
        </div>
    @endif
</x-layouts.app>
