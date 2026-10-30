@php
    use App\Models\Task;

    $canCreate = auth()->user()->can('create', Task::class);
    $filtered = array_filter($filters);
    $total = $tasks->sum(fn ($group) => $group->count());
@endphp
<x-tasks.page active="quadro" :subtitle="App\Support\Plural::of($total, 'tarefa', 'tarefas').($filtered !== [] ? ' com estes filtros' : '')">
    @if ($canCreate)
        <x-slot:actions>
            <button type="button" class="btn btn-primary" data-dialog-open="task-create">Nova tarefa</button>
        </x-slot:actions>
    @endif

    <form method="GET" action="{{ route('tasks.index') }}" class="mt-4 grid grid-cols-2 gap-2 sm:grid-cols-12" role="search">
        <div class="col-span-2 sm:col-span-3">
            <label for="q" class="sr-only">Buscar</label>
            <input id="q" name="q" type="search" value="{{ $filters['q'] }}" maxlength="100" class="input" placeholder="Buscar por título">
        </div>
        <div class="sm:col-span-3">
            <label for="responsavel" class="sr-only">Responsável</label>
            <select id="responsavel" name="responsavel" class="input">
                <option value="">Todos os responsáveis</option>
                @foreach ($users as $user)
                    <option value="{{ $user->id }}" @selected($filters['responsavel'] === $user->id)>{{ $user->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="sm:col-span-2">
            <label for="prioridade" class="sr-only">Prioridade</label>
            <select id="prioridade" name="prioridade" class="input">
                <option value="">Toda prioridade</option>
                @foreach ($priorities as $value => $label)
                    <option value="{{ $value }}" @selected($filters['prioridade'] === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div class="sm:col-span-2">
            <label for="etiqueta" class="sr-only">Etiqueta</label>
            <select id="etiqueta" name="etiqueta" class="input">
                <option value="">Toda etiqueta</option>
                @foreach ($labels as $label)
                    <option value="{{ $label->id }}" @selected($filters['etiqueta'] === $label->id)>{{ $label->name }}</option>
                @endforeach
            </select>
        </div>
        <button type="submit" class="btn btn-secondary col-span-2 sm:col-span-2">Filtrar</button>
        <div class="col-span-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm sm:col-span-12">
            <label class="flex items-center gap-2">
                <input type="hidden" name="minhas" value="0">
                <input type="checkbox" name="minhas" value="1" class="size-4 rounded border-slate-300 accent-brand" @checked($filters['minhas']) data-autosubmit>
                Só as minhas
            </label>
            @if ($filtered !== [])
                <a href="{{ route('tasks.index') }}" class="text-slate-600 hover:underline">Limpar filtros</a>
            @endif
        </div>
    </form>

    {{-- Uma coluna por etapa, lado a lado no computador e empilhadas no celular. --}}
    <div class="mt-4 relative overflow-x-auto pb-2" data-task-board>
        <ol class="flex flex-col gap-3 sm:flex-row sm:items-start" aria-label="Colunas do quadro">
            @foreach ($columns as $column)
                @php $items = $tasks->get($column->id, collect()); @endphp
                <li class="flex min-w-0 flex-col rounded-xl border border-slate-200 bg-slate-50 sm:w-72 sm:shrink-0" data-task-column="{{ $column->id }}">
                    <h2 class="flex items-center justify-between gap-2 px-3 py-2.5 text-sm font-semibold text-slate-800">
                        <span class="truncate">{{ $column->name }}</span>
                        <span class="rounded-full bg-white px-2 py-0.5 text-xs font-medium text-slate-600 tabular-nums" data-task-count>{{ $items->count() }}</span>
                    </h2>
                    <ul class="min-h-16 space-y-2 px-2 pb-2" data-task-list aria-label="Tarefas em {{ $column->name }}">
                        @foreach ($items as $task)
                            @include('tasks._card', ['task' => $task])
                        @endforeach
                    </ul>
                    <p @class(['px-3 pb-3 text-xs text-slate-500', 'hidden' => $items->isNotEmpty()]) data-task-empty>Nenhuma tarefa.</p>
                </li>
            @endforeach
        </ol>
    </div>

    @if ($canCreate)
        <x-dialog id="task-create" title="Nova tarefa" size="lg">
            <form method="POST" action="{{ route('tasks.store') }}" class="space-y-5" novalidate>
                @csrf
                <input type="hidden" name="_dialog" value="task-create">
                <x-form-errors scope="task-create" />
                @include('tasks._task_fields', ['scope' => 'task-create', 'task' => null])
                <div class="flex flex-wrap justify-end gap-2 border-t border-slate-200 pt-4">
                    <button type="button" class="btn btn-secondary" data-dialog-close>Cancelar</button>
                    <button type="submit" class="btn btn-primary">Criar tarefa</button>
                </div>
            </form>
        </x-dialog>
    @endif

    @if ($open)
        @include('tasks._panel', ['task' => $open])
    @endif
</x-tasks.page>
