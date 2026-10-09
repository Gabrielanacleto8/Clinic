@php
    use App\Enums\ProcedureCategory;
    use App\Support\Duration;
    use App\Support\LocalizedDecimal;
@endphp
<x-layouts.app title="Procedimentos">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold tracking-tight">Procedimentos</h1>
            <p class="mt-1 text-sm text-slate-600">{{ $procedures->total() }} {{ $procedures->total() === 1 ? 'registro' : 'registros' }}</p>
        </div>
        @can('create', App\Models\Procedure::class)
            <a href="{{ route('procedures.create') }}" class="btn btn-primary">Novo procedimento</a>
        @endcan
    </div>

    <form method="GET" action="{{ route('procedures.index') }}" class="mt-6 grid grid-cols-1 gap-2 sm:grid-cols-12" role="search">
        <div class="sm:col-span-5">
            <label for="q" class="sr-only">Buscar por nome</label>
            <input id="q" name="q" type="search" value="{{ $filters['q'] }}" maxlength="100" class="input" placeholder="Buscar por nome">
        </div>
        <div class="sm:col-span-3">
            <label for="categoria" class="sr-only">Categoria</label>
            <select id="categoria" name="categoria" class="input">
                <option value="">Todas as categorias</option>
                @foreach (ProcedureCategory::options() as $value => $label)
                    <option value="{{ $value }}" @selected($filters['categoria'] === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div class="sm:col-span-2">
            <label for="situacao" class="sr-only">Situação</label>
            <select id="situacao" name="situacao" class="input">
                @foreach (['ativos' => 'Ativos', 'inativos' => 'Inativos', 'todos' => 'Todos'] as $value => $label)
                    <option value="{{ $value }}" @selected($filters['situacao'] === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <button type="submit" class="btn btn-secondary sm:col-span-2">Filtrar</button>
        @foreach (['q', 'categoria', 'situacao'] as $field)
            @error($field)
                <p class="text-sm text-red-700 sm:col-span-12">{{ $message }}</p>
            @enderror
        @endforeach
    </form>

    <div class="card mt-4 relative overflow-x-auto">
        <table class="table-stack w-full min-w-2xl text-left text-sm">
            <thead class="border-b border-slate-200 bg-slate-50 text-xs tracking-wide text-slate-500 uppercase">
                <tr>
                    <th scope="col" class="px-4 py-3 font-medium">Procedimento</th>
                    <th scope="col" class="px-4 py-3 font-medium">Categoria</th>
                    <th scope="col" class="px-4 py-3 font-medium">Duração</th>
                    <th scope="col" class="px-4 py-3 text-right font-medium">Valor</th>
                    <th scope="col" class="px-4 py-3 font-medium">Situação</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($procedures as $procedure)
                    {{-- A linha inteira abre os detalhes; o nome é um botão para valer também no teclado. --}}
                    <tr class="cursor-pointer hover:bg-slate-50" data-dialog-open="procedure-{{ $procedure->id }}">
                        <td class="px-4 py-3">
                            <button type="button" class="cursor-pointer text-left font-medium text-teal-800 hover:underline" data-dialog-open="procedure-{{ $procedure->id }}">{{ $procedure->name }}</button>
                            @if ($procedure->description)
                                <p class="mt-0.5 max-w-md truncate text-xs text-slate-500" title="{{ $procedure->description }}">{{ $procedure->description }}</p>
                            @endif
                        </td>
                        <td data-label="Categoria" class="px-4 py-3 text-slate-600">{{ $procedure->category->label() }}</td>
                        <td data-label="Duração" class="px-4 py-3 whitespace-nowrap text-slate-600">{{ Duration::label($procedure->duration_minutes) }}</td>
                        <td data-label="Valor" class="px-4 py-3 text-right whitespace-nowrap tabular-nums">{{ LocalizedDecimal::money($procedure->price) }}</td>
                        <td data-label="Situação" class="px-4 py-3">
                            @if ($procedure->is_active)
                                <span class="badge bg-emerald-100 text-emerald-800">Ativo</span>
                            @else
                                <span class="badge bg-slate-200 text-slate-700">Inativo</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-10 text-center text-slate-500">Nenhum procedimento encontrado.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $procedures->links() }}
    </div>

    {{-- Detalhes de cada procedimento da página, abertos pelo clique na linha. --}}
    @foreach ($procedures as $procedure)
        <x-dialog id="procedure-{{ $procedure->id }}" :title="$procedure->name">
            <dl class="grid grid-cols-2 gap-x-4 gap-y-4 text-sm">
                <div>
                    <dt class="text-xs text-slate-500">Categoria</dt>
                    <dd class="mt-0.5">{{ $procedure->category->label() }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-slate-500">Situação</dt>
                    <dd class="mt-0.5">
                        @if ($procedure->is_active)
                            <span class="badge bg-emerald-100 text-emerald-800">Ativo</span>
                        @else
                            <span class="badge bg-slate-200 text-slate-700">Inativo</span>
                        @endif
                    </dd>
                </div>
                <div>
                    <dt class="text-xs text-slate-500">Duração estimada</dt>
                    <dd class="mt-0.5">{{ Duration::label($procedure->duration_minutes) }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-slate-500">Valor</dt>
                    <dd class="mt-0.5 tabular-nums">{{ LocalizedDecimal::money($procedure->price) }}</dd>
                </div>
                <div class="col-span-2">
                    <dt class="text-xs text-slate-500">Descrição</dt>
                    <dd class="mt-0.5 break-words whitespace-pre-line">{{ $procedure->description ?: '—' }}</dd>
                </div>
                <div class="col-span-2">
                    <dt class="text-xs text-slate-500">Produtos normalmente usados</dt>
                    <dd class="mt-0.5">
                        @if ($procedure->products->isEmpty())
                            Nenhum produto definido.
                        @else
                            <ul class="divide-y divide-slate-100">
                                @foreach ($procedure->products as $product)
                                    <li class="flex items-center justify-between gap-3 py-1.5">
                                        <span class="min-w-0 break-words">{{ $product->name }}</span>
                                        <span class="shrink-0 whitespace-nowrap text-slate-600 tabular-nums">{{ LocalizedDecimal::quantity($product->pivot->quantity) }} {{ $product->unit->symbol() }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </dd>
                </div>
            </dl>

            <div class="mt-5 flex justify-end gap-2">
                <button type="button" class="btn btn-secondary" data-dialog-close>Fechar</button>
                @can('update', $procedure)
                    <a href="{{ route('procedures.edit', $procedure) }}" class="btn btn-primary">Editar</a>
                @endcan
            </div>
        </x-dialog>
    @endforeach
</x-layouts.app>
