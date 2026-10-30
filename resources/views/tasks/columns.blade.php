<x-tasks.page active="colunas" title="Colunas do quadro" subtitle="Cada coluna é uma etapa. O tipo diz o que estar nela significa: em aberto, concluída ou cancelada.">
    @error('column')
        <p class="mt-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900" role="alert">{{ $message }}</p>
    @enderror

    <div class="card mt-4 relative overflow-x-auto">
        <table class="table-stack w-full min-w-xl text-left text-sm">
            <thead class="border-b border-slate-200 bg-slate-50 text-xs tracking-wide text-slate-500 uppercase">
                <tr>
                    <th scope="col" class="w-20 px-4 py-3 font-medium">Ordem</th>
                    <th scope="col" class="px-4 py-3 font-medium">Nome</th>
                    <th scope="col" class="w-40 px-4 py-3 font-medium">Tipo</th>
                    <th scope="col" class="w-24 px-4 py-3 text-right font-medium">Tarefas</th>
                    <th scope="col" class="w-44 px-4 py-3 font-medium"><span class="sr-only">Ações</span></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @foreach ($columns as $column)
                    @php $formId = 'column-'.$column->id; @endphp
                    <tr>
                        <td data-label="Ordem" class="px-4 py-2">
                            <label for="{{ $formId }}-position" class="sr-only">Ordem</label>
                            <input form="{{ $formId }}" id="{{ $formId }}-position" name="position" type="number" min="1" max="50" value="{{ $column->position }}" class="input w-16 py-1 text-right tabular-nums">
                        </td>
                        <td data-label="Nome" class="px-4 py-2">
                            <label for="{{ $formId }}-name" class="sr-only">Nome</label>
                            <input form="{{ $formId }}" id="{{ $formId }}-name" name="name" type="text" value="{{ $column->name }}" maxlength="60" required class="input py-1">
                        </td>
                        <td data-label="Tipo" class="px-4 py-2">
                            <label for="{{ $formId }}-kind" class="sr-only">Tipo</label>
                            <select form="{{ $formId }}" id="{{ $formId }}-kind" name="kind" class="input py-1">
                                @foreach ($kinds as $value => $label)
                                    <option value="{{ $value }}" @selected($column->kind->value === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </td>
                        <td data-label="Tarefas" class="px-4 py-2 text-right tabular-nums">{{ $column->tasks_count }}</td>
                        <td class="px-4 py-2">
                            <div class="flex items-center justify-end gap-2">
                                <form id="{{ $formId }}" method="POST" action="{{ route('tasks.columns.update', $column) }}" novalidate>
                                    @csrf
                                    @method('PUT')
                                    <button type="submit" class="btn btn-secondary px-2.5 py-1 text-xs">Salvar</button>
                                </form>
                                <form method="POST" action="{{ route('tasks.columns.destroy', $column) }}" data-confirm="Excluir a coluna {{ $column->name }}?">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-secondary px-2.5 py-1 text-xs text-red-700" @disabled($column->tasks_count > 0 || $columns->count() <= 1)>Excluir</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <section class="card mt-6 p-6" aria-labelledby="new-column">
        <h2 id="new-column" class="text-sm font-semibold tracking-wide text-slate-500 uppercase">Nova coluna</h2>
        <form method="POST" action="{{ route('tasks.columns.store') }}" class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-6" novalidate>
            @csrf
            <x-form.input class="sm:col-span-3" name="name" label="Nome" required maxlength="60" autocomplete="off" />
            <x-form.select class="sm:col-span-2" name="kind" label="Tipo" :options="$kinds" required selected="open" />
            <x-form.input class="sm:col-span-1" name="position" label="Ordem" type="number" min="1" max="50" required :value="$columns->count() + 1" />
            <div class="flex justify-end sm:col-span-6">
                <button type="submit" class="btn btn-primary">Criar coluna</button>
            </div>
        </form>
    </section>
</x-tasks.page>
