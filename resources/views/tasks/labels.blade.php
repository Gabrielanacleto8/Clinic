<x-tasks.page active="etiquetas" title="Etiquetas" subtitle="Categorias para marcar as tarefas. Excluir uma etiqueta só a tira das tarefas.">
    <div class="card mt-4 relative overflow-x-auto">
        <table class="table-stack w-full min-w-xl text-left text-sm">
            <thead class="border-b border-slate-200 bg-slate-50 text-xs tracking-wide text-slate-500 uppercase">
                <tr>
                    <th scope="col" class="px-4 py-3 font-medium">Etiqueta</th>
                    <th scope="col" class="w-40 px-4 py-3 font-medium">Cor</th>
                    <th scope="col" class="w-24 px-4 py-3 text-right font-medium">Tarefas</th>
                    <th scope="col" class="w-44 px-4 py-3 font-medium"><span class="sr-only">Ações</span></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($labels as $label)
                    @php $formId = 'label-'.$label->id; @endphp
                    <tr>
                        <td data-label="Etiqueta" class="px-4 py-2">
                            <div class="flex items-center gap-3">
                                <span class="badge {{ $label->badgeClasses() }}">{{ $label->name }}</span>
                                <label for="{{ $formId }}-name" class="sr-only">Nome</label>
                                <input form="{{ $formId }}" id="{{ $formId }}-name" name="name" type="text" value="{{ $label->name }}" maxlength="40" required class="input max-w-xs py-1">
                            </div>
                        </td>
                        <td data-label="Cor" class="px-4 py-2">
                            <label for="{{ $formId }}-color" class="sr-only">Cor</label>
                            <select form="{{ $formId }}" id="{{ $formId }}-color" name="color" class="input py-1">
                                @foreach ($colors as $value => $name)
                                    <option value="{{ $value }}" @selected($label->color === $value)>{{ $name }}</option>
                                @endforeach
                            </select>
                        </td>
                        <td data-label="Tarefas" class="px-4 py-2 text-right tabular-nums">{{ $label->tasks_count }}</td>
                        <td class="px-4 py-2">
                            <div class="flex items-center justify-end gap-2">
                                <form id="{{ $formId }}" method="POST" action="{{ route('tasks.labels.update', $label) }}" novalidate>
                                    @csrf
                                    @method('PUT')
                                    <button type="submit" class="btn btn-secondary px-2.5 py-1 text-xs">Salvar</button>
                                </form>
                                <form method="POST" action="{{ route('tasks.labels.destroy', $label) }}" data-confirm="Excluir a etiqueta {{ $label->name }}?">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-secondary px-2.5 py-1 text-xs text-red-700">Excluir</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="px-4 py-10 text-center text-slate-500">Nenhuma etiqueta ainda.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <section class="card mt-6 p-6" aria-labelledby="new-label">
        <h2 id="new-label" class="text-sm font-semibold tracking-wide text-slate-500 uppercase">Nova etiqueta</h2>
        <form method="POST" action="{{ route('tasks.labels.store') }}" class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-6" novalidate>
            @csrf
            <x-form.input class="sm:col-span-4" name="name" label="Nome" required maxlength="40" autocomplete="off" />
            <x-form.select class="sm:col-span-2" name="color" label="Cor" :options="$colors" required selected="slate" />
            <div class="flex justify-end sm:col-span-6">
                <button type="submit" class="btn btn-primary">Criar etiqueta</button>
            </div>
        </form>
    </section>
</x-tasks.page>
