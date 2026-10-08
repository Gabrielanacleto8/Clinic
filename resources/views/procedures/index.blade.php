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

    <div class="card mt-4 overflow-x-auto">
        <table class="w-full min-w-2xl text-left text-sm">
            <thead class="border-b border-slate-200 bg-slate-50 text-xs tracking-wide text-slate-500 uppercase">
                <tr>
                    <th scope="col" class="px-4 py-3 font-medium">Procedimento</th>
                    <th scope="col" class="px-4 py-3 font-medium">Categoria</th>
                    <th scope="col" class="px-4 py-3 font-medium">Duração</th>
                    <th scope="col" class="px-4 py-3 text-right font-medium">Valor</th>
                    <th scope="col" class="px-4 py-3 font-medium">Situação</th>
                    <th scope="col" class="px-4 py-3"><span class="sr-only">Ações</span></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($procedures as $procedure)
                    <tr class="hover:bg-slate-50">
                        <td class="px-4 py-3">
                            <p class="font-medium">{{ $procedure->name }}</p>
                            @if ($procedure->description)
                                <p class="mt-0.5 max-w-md truncate text-xs text-slate-500" title="{{ $procedure->description }}">{{ $procedure->description }}</p>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-slate-600">{{ $procedure->category->label() }}</td>
                        <td class="px-4 py-3 whitespace-nowrap text-slate-600">{{ Duration::label($procedure->duration_minutes) }}</td>
                        <td class="px-4 py-3 text-right whitespace-nowrap tabular-nums">{{ LocalizedDecimal::money($procedure->price) }}</td>
                        <td class="px-4 py-3">
                            @if ($procedure->is_active)
                                <span class="badge bg-emerald-100 text-emerald-800">Ativo</span>
                            @else
                                <span class="badge bg-slate-200 text-slate-700">Inativo</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right">
                            @can('update', $procedure)
                                <a href="{{ route('procedures.edit', $procedure) }}" class="font-medium text-teal-800 hover:underline">Editar</a>
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-10 text-center text-slate-500">Nenhum procedimento encontrado.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $procedures->links() }}
    </div>
</x-layouts.app>
