@php
    use App\Support\ClinicTime;

    // Painel da tarefa aberta, em duas colunas: à esquerda título, abas (subtarefas,
    // comentários, anexos, histórico); à direita os detalhes, editáveis por quem pode.
    // Os campos de edição apontam para o formulário "task-edit-form" pelo atributo form,
    // para não envolver os formulários das abas. Cada formulário tem o próprio "_dialog".
    $user = auth()->user();
    $canUpdate = $user->can('update', $task);
    $canDelete = $user->can('delete', $task);
    $total = $task->subtasks->count();
    $done = $task->subtasks->whereNotNull('completed_at')->count();
    $progress = $task->progress();
    $scoped = fn (string $scope) => old('_dialog') === $scope;
    $editScope = 'task-edit';
    $editing = $scoped($editScope);
    $value = fn (string $field, $current) => $editing ? old($field, $current) : $current;
    $today = ClinicTime::today();
    $userName = fn ($person) => $person?->name ?? 'Usuário removido';
    $initials = fn ($person) => $person ? mb_strtoupper(mb_substr($person->name, 0, 1).(str_contains(trim($person->name), ' ') ? mb_substr(substr(strrchr(trim($person->name), ' '), 1), 0, 1) : '')) : '—';
    $selectedLabels = $editing ? array_map('intval', (array) old('labels', [])) : $task->labels->pluck('id')->all();
    // A aba aberta: a do formulário que voltou com erro, senão a do endereço.
    $activeTab = match (old('_dialog')) {
        'task-subtask', 'task-item' => 'subtarefas',
        'task-comment' => 'comentarios',
        'task-attachment' => 'anexos',
        default => $openTab,
    };
    $tabs = [
        'subtarefas' => ['Subtarefas', 'check-square', $total],
        'comentarios' => ['Comentários', 'message', $task->comments->count()],
        'anexos' => ['Anexos', 'paperclip', $task->attachments->count()],
        'historico' => ['Histórico', 'eye', null],
    ];
    $avatar = 'flex size-8 shrink-0 items-center justify-center rounded-full bg-slate-200 text-xs font-semibold text-slate-700';
    $fieldClass = 'input py-1.5 text-sm';
@endphp
<x-dialog id="task-panel" :title="'Tarefa #'.$task->id" size="xl" :autoshow="true" :reopens="false" class="max-h-[calc(100%-2rem)]">
    @if ($canUpdate)
        <form id="task-edit-form" method="POST" action="{{ route('tasks.update', $task) }}" novalidate>
            @csrf
            @method('PUT')
            <input type="hidden" name="_dialog" value="{{ $editScope }}">
        </form>
    @endif

    <x-form-errors :scope="$editScope" class="mb-4" />

    <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_17rem]">
        <div class="min-w-0">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="min-w-0 flex-1">
                    @if ($canUpdate)
                        <label for="task-edit-title" class="sr-only">Título</label>
                        <input id="task-edit-title" form="task-edit-form" name="title" type="text" required maxlength="150" autocomplete="off" value="{{ $value('title', $task->title) }}"
                               @class(['w-full rounded-lg border border-transparent bg-transparent px-1 py-0.5 text-2xl font-semibold tracking-tight text-slate-900 -mx-1 hover:border-slate-200 focus:border-slate-300 focus:outline-none', 'input-error' => $editing && $errors->has('title')])>
                        @if ($editing)
                            @error('title')
                                <p class="mt-1 text-sm text-red-700">{{ $message }}</p>
                            @enderror
                        @endif
                    @else
                        <h3 class="text-2xl font-semibold tracking-tight text-slate-900">{{ $task->title }}</h3>
                    @endif
                    @if ($task->description)
                        <p class="mt-2 text-sm whitespace-pre-line text-slate-600">{{ $task->description }}</p>
                    @endif
                    <div class="mt-3 flex flex-wrap items-center gap-1.5">
                        <span class="badge {{ $task->priority->badgeClasses() }}">{{ $task->priority->label() }} prioridade</span>
                        @foreach ($task->labels as $label)
                            <span class="badge {{ $label->badgeClasses() }}">{{ $label->name }}</span>
                        @endforeach
                        @if ($task->isOverdue())
                            <span class="badge bg-red-100 text-red-800">Atrasada</span>
                        @endif
                    </div>
                </div>
                @if ($progress !== null)
                    <div class="w-40 shrink-0 text-xs text-slate-500">
                        <p class="font-medium text-slate-700">Progresso</p>
                        <p class="mt-1 flex items-center justify-between"><span>{{ $done }} de {{ $total }} subtarefas</span><span class="font-semibold text-slate-900 tabular-nums">{{ $progress }}%</span></p>
                        <progress class="bar mt-1.5 text-sky-600" max="100" value="{{ $progress }}" aria-label="Progresso da tarefa">{{ $progress }}%</progress>
                    </div>
                @endif
            </div>

            <div class="mt-5 flex flex-wrap gap-x-5 border-b border-slate-200" role="tablist" aria-label="Seções da tarefa" data-panel-tabs>
                @foreach ($tabs as $key => [$label, $icon, $count])
                    <button type="button" role="tab" data-panel-tab="{{ $key }}" aria-selected="{{ $activeTab === $key ? 'true' : 'false' }}" aria-controls="task-section-{{ $key }}"
                            class="-mb-px flex cursor-pointer items-center gap-1.5 border-b-2 border-transparent px-0.5 pt-1 pb-2.5 text-sm font-medium text-slate-500 transition-colors hover:text-slate-900 aria-selected:border-slate-900 aria-selected:text-slate-900">
                        <x-icon :name="$icon" class="size-4" />{{ $label }}@if ($count)<span class="text-xs text-slate-400 tabular-nums">({{ $count }})</span>@endif
                    </button>
                @endforeach
            </div>

            {{-- Subtarefas --}}
            <section id="task-section-subtarefas" role="tabpanel" @class(['mt-4', 'hidden' => $activeTab !== 'subtarefas']) data-panel-section="subtarefas">
                <x-form-errors scope="task-subtask" class="mb-3" />
                <x-form-errors scope="task-item" class="mb-3" />
                <ul class="divide-y divide-slate-100">
                    @forelse ($task->subtasks as $subtask)
                        @php
                            $late = ! $subtask->isCompleted() && $subtask->due_on !== null && $subtask->due_on->format('Y-m-d') < $today;
                            $itemsDone = $subtask->items->whereNotNull('done_at')->count();
                        @endphp
                        <li class="py-2.5">
                            <div class="flex items-center gap-3">
                                @if ($canUpdate)
                                    <form method="POST" action="{{ route('tasks.subtasks.toggle', [$task, $subtask]) }}" class="shrink-0">
                                        @csrf
                                        <button type="submit" @class(['flex size-5 items-center justify-center rounded border transition-colors', 'border-sky-600 bg-sky-600 text-white' => $subtask->isCompleted(), 'border-slate-300 bg-white hover:border-slate-500' => ! $subtask->isCompleted()])
                                                aria-label="{{ $subtask->isCompleted() ? 'Reabrir' : 'Concluir' }}: {{ $subtask->title }}" aria-pressed="{{ $subtask->isCompleted() ? 'true' : 'false' }}">
                                            @if ($subtask->isCompleted())<x-icon name="check" class="size-3.5" />@endif
                                        </button>
                                    </form>
                                @else
                                    <span @class(['flex size-5 shrink-0 items-center justify-center rounded border', 'border-sky-600 bg-sky-600 text-white' => $subtask->isCompleted(), 'border-slate-300 bg-white' => ! $subtask->isCompleted()])>@if ($subtask->isCompleted())<x-icon name="check" class="size-3.5" />@endif</span>
                                @endif
                                <div class="min-w-0 flex-1">
                                    <p @class(['text-sm font-medium', 'text-slate-500 line-through' => $subtask->isCompleted(), 'text-slate-900' => ! $subtask->isCompleted()])>{{ $subtask->title }}</p>
                                    @if ($subtask->description)
                                        <p class="truncate text-xs text-slate-500">{{ $subtask->description }}</p>
                                    @endif
                                </div>
                                <span class="{{ $avatar }} size-7 text-[11px]" title="{{ $subtask->assignee?->name ?? 'Sem responsável' }}">{{ $initials($subtask->assignee) }}</span>
                                <span class="hidden w-20 text-right text-xs text-slate-600 tabular-nums sm:block">{{ $subtask->due_on?->format('d/m/Y') ?? '—' }}</span>
                                <span @class(['badge w-24 justify-center', 'bg-emerald-100 text-emerald-800' => $subtask->isCompleted(), 'bg-red-100 text-red-800' => $late, 'bg-slate-100 text-slate-700' => ! $subtask->isCompleted() && ! $late])>
                                    {{ $subtask->isCompleted() ? 'Concluída' : ($late ? 'Atrasada' : 'Pendente') }}
                                </span>
                                @if ($canUpdate)
                                    <form method="POST" action="{{ route('tasks.subtasks.destroy', [$task, $subtask]) }}" data-confirm="Remover a subtarefa e o checklist dela?">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn px-1.5 text-slate-400 hover:bg-slate-100 hover:text-red-700" aria-label="Remover subtarefa: {{ $subtask->title }}"><x-icon name="close" class="size-4" /></button>
                                    </form>
                                @endif
                            </div>

                            @if ($subtask->items->isNotEmpty() || $canUpdate)
                                <details class="mt-1.5 ml-8" @if ($scoped('task-item') && (int) old('_subtask') === $subtask->id) open @endif>
                                    <summary class="cursor-pointer text-xs text-slate-500 select-none hover:text-slate-800">Checklist{{ $subtask->items->isNotEmpty() ? " ({$itemsDone}/{$subtask->items->count()})" : '' }}</summary>
                                    <ul class="mt-1.5 space-y-1" aria-label="Checklist de {{ $subtask->title }}">
                                        @foreach ($subtask->items as $item)
                                            <li class="flex items-center gap-2 text-sm">
                                                @if ($canUpdate)
                                                    <form method="POST" action="{{ route('tasks.items.toggle', [$task, $subtask, $item]) }}">
                                                        @csrf
                                                        <button type="submit" @class(['flex size-4 items-center justify-center rounded-sm border', 'border-sky-600 bg-sky-600 text-white' => $item->isDone(), 'border-slate-300 bg-white hover:border-slate-500' => ! $item->isDone()])
                                                                aria-label="{{ $item->isDone() ? 'Desmarcar' : 'Marcar' }}: {{ $item->title }}" aria-pressed="{{ $item->isDone() ? 'true' : 'false' }}">
                                                            @if ($item->isDone())<x-icon name="check" class="size-3" />@endif
                                                        </button>
                                                    </form>
                                                @endif
                                                <span @class(['min-w-0 flex-1', 'text-slate-500 line-through' => $item->isDone()])>{{ $item->title }}</span>
                                                @if ($canUpdate)
                                                    <form method="POST" action="{{ route('tasks.items.destroy', [$task, $subtask, $item]) }}">
                                                        @csrf
                                                        @method('DELETE')
                                                        <button type="submit" class="text-xs text-slate-400 hover:text-red-700" aria-label="Remover atividade: {{ $item->title }}">remover</button>
                                                    </form>
                                                @endif
                                            </li>
                                        @endforeach
                                        @if ($canUpdate)
                                            <li>
                                                <form method="POST" action="{{ route('tasks.items.store', [$task, $subtask]) }}" class="flex items-center gap-2" novalidate>
                                                    @csrf
                                                    <input type="hidden" name="_dialog" value="task-item">
                                                    <input type="hidden" name="_subtask" value="{{ $subtask->id }}">
                                                    <label for="item-{{ $subtask->id }}" class="sr-only">Nova atividade do checklist</label>
                                                    <input id="item-{{ $subtask->id }}" name="title" type="text" maxlength="150" autocomplete="off" placeholder="Nova atividade"
                                                           value="{{ $scoped('task-item') && (int) old('_subtask') === $subtask->id ? old('title') : '' }}" class="input max-w-xs py-1 text-sm">
                                                    <button type="submit" class="btn btn-secondary px-2.5 py-1 text-xs">Adicionar</button>
                                                </form>
                                            </li>
                                        @endif
                                    </ul>
                                </details>
                            @endif
                        </li>
                    @empty
                        <li class="py-6 text-center text-sm text-slate-500">Nenhuma subtarefa ainda.</li>
                    @endforelse
                </ul>

                @if ($canUpdate)
                    <details class="group/new mt-3" @if ($scoped('task-subtask')) open @endif>
                        <summary class="btn btn-secondary inline-flex w-auto cursor-pointer list-none items-center gap-1.5 text-sm select-none [&::-webkit-details-marker]:hidden"><span aria-hidden="true">+</span> Adicionar subtarefa</summary>
                        <form method="POST" action="{{ route('tasks.subtasks.store', $task) }}" class="mt-3 grid grid-cols-1 gap-3 rounded-lg border border-slate-200 bg-slate-50 p-4 sm:grid-cols-6" novalidate>
                            @csrf
                            <input type="hidden" name="_dialog" value="task-subtask">
                            <x-form.input class="sm:col-span-6" name="title" id="subtask-title" scope="task-subtask" label="Título" required maxlength="150" autocomplete="off" />
                            <x-form.input class="sm:col-span-6" name="description" id="subtask-description" scope="task-subtask" label="Descrição" maxlength="2000" autocomplete="off" />
                            <x-form.select class="sm:col-span-3" name="assignee_id" id="subtask-assignee" scope="task-subtask" label="Responsável" :options="$users->pluck('name', 'id')->all()" placeholder="Sem responsável" />
                            <x-form.input class="sm:col-span-3" name="due_on" id="subtask-due" scope="task-subtask" label="Prazo" type="date" />
                            <div class="flex justify-end sm:col-span-6">
                                <button type="submit" class="btn btn-primary">Adicionar subtarefa</button>
                            </div>
                        </form>
                    </details>
                @endif
            </section>

            {{-- Comentários --}}
            <section id="task-section-comentarios" role="tabpanel" @class(['mt-4', 'hidden' => $activeTab !== 'comentarios']) data-panel-section="comentarios">
                <x-form-errors scope="task-comment" class="mb-3" />
                <ul class="space-y-4">
                    @forelse ($task->comments as $comment)
                        <li class="flex gap-3">
                            <span class="{{ $avatar }}" aria-hidden="true">{{ $initials($comment->user) }}</span>
                            <div class="min-w-0 flex-1 rounded-lg bg-slate-50 px-3 py-2">
                                <div class="flex flex-wrap items-center gap-x-2 text-xs text-slate-500">
                                    <span class="font-medium text-slate-800">{{ $userName($comment->user) }}</span>
                                    <span>{{ ClinicTime::format($comment->created_at) }}</span>
                                    @can('deleteComment', [$task, $comment])
                                        <form method="POST" action="{{ route('tasks.comments.destroy', [$task, $comment]) }}" class="ml-auto" data-confirm="Remover o comentário?">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="text-slate-400 hover:text-red-700">remover</button>
                                        </form>
                                    @endcan
                                </div>
                                <p class="mt-1 text-sm whitespace-pre-line text-slate-800">{{ $comment->body }}</p>
                            </div>
                        </li>
                    @empty
                        <li class="py-6 text-center text-sm text-slate-500">Nenhum comentário ainda.</li>
                    @endforelse
                </ul>
                @can('comment', $task)
                    <h4 class="mt-6 text-sm font-semibold text-slate-900">Adicionar comentário</h4>
                    <form method="POST" action="{{ route('tasks.comments.store', $task) }}" class="mt-2 flex gap-3" novalidate>
                        @csrf
                        <input type="hidden" name="_dialog" value="task-comment">
                        <span class="{{ $avatar }}" aria-hidden="true">{{ $initials($user) }}</span>
                        <div class="min-w-0 flex-1 rounded-lg border border-slate-200 focus-within:border-slate-400">
                            <label for="task-comment-body" class="sr-only">Comentário</label>
                            <textarea id="task-comment-body" name="body" rows="2" maxlength="2000" required placeholder="Escreva um comentário…"
                                      class="w-full resize-y rounded-t-lg border-0 bg-transparent px-3 py-2 text-sm placeholder:text-slate-400 focus:outline-none">{{ $scoped('task-comment') ? old('body') : '' }}</textarea>
                            @if ($scoped('task-comment'))
                                @error('body')
                                    <p class="px-3 text-sm text-red-700">{{ $message }}</p>
                                @enderror
                            @endif
                            <div class="flex justify-end border-t border-slate-100 px-2 py-1.5">
                                <button type="submit" class="btn btn-secondary px-3 py-1 text-xs">Comentar</button>
                            </div>
                        </div>
                    </form>
                @endcan
            </section>

            {{-- Anexos --}}
            <section id="task-section-anexos" role="tabpanel" @class(['mt-4', 'hidden' => $activeTab !== 'anexos']) data-panel-section="anexos">
                <x-form-errors scope="task-attachment" class="mb-3" />
                <ul class="divide-y divide-slate-100">
                    @forelse ($task->attachments as $attachment)
                        <li class="flex flex-wrap items-center gap-x-3 gap-y-1 py-2.5 text-sm">
                            <x-icon name="paperclip" class="size-4 text-slate-400" />
                            <a href="{{ route('tasks.attachments.show', [$task, $attachment]) }}" class="min-w-0 flex-1 truncate font-medium text-slate-800 hover:underline">{{ $attachment->name }}.{{ $attachment->extension }}</a>
                            <span class="text-xs text-slate-500">{{ $attachment->sizeLabel() }} · {{ $userName($attachment->uploader) }} · {{ ClinicTime::format($attachment->created_at, 'd/m/Y') }}</span>
                            @if ($canUpdate)
                                <form method="POST" action="{{ route('tasks.attachments.destroy', [$task, $attachment]) }}" data-confirm="Remover o anexo?">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="text-xs text-slate-400 hover:text-red-700">remover</button>
                                </form>
                            @endif
                        </li>
                    @empty
                        <li class="py-6 text-center text-sm text-slate-500">Nenhum anexo ainda.</li>
                    @endforelse
                </ul>
                @if ($canUpdate)
                    <form method="POST" action="{{ route('tasks.attachments.store', $task) }}" enctype="multipart/form-data" class="mt-4 grid grid-cols-1 gap-3 rounded-lg border border-slate-200 bg-slate-50 p-4 sm:grid-cols-6" novalidate>
                        @csrf
                        <input type="hidden" name="_dialog" value="task-attachment">
                        <x-form.input class="sm:col-span-3" name="attachment" id="task-attachment-file" scope="task-attachment" label="Arquivo" type="file" required
                                      accept=".pdf,.png,.jpg,.jpeg,.docx,.xlsx" hint="PDF, PNG, JPG, DOCX ou XLSX, até {{ (int) (config('clinic.documents.max_upload_kilobytes') / 1024) }} MB." />
                        <x-form.input class="sm:col-span-2" name="name" id="task-attachment-name" scope="task-attachment" label="Nome (opcional)" maxlength="100" autocomplete="off" />
                        <div class="flex items-end sm:col-span-1">
                            <button type="submit" class="btn btn-secondary w-full">Anexar</button>
                        </div>
                    </form>
                @endif
            </section>

            {{-- Histórico --}}
            <section id="task-section-historico" role="tabpanel" @class(['mt-4', 'hidden' => $activeTab !== 'historico']) data-panel-section="historico">
                <ol class="space-y-2 text-sm text-slate-600">
                    @foreach ($task->activities as $activity)
                        <li class="flex gap-3">
                            <span class="{{ $avatar }} size-7 text-[11px]" aria-hidden="true">{{ $initials($activity->user) }}</span>
                            <p class="pt-1"><span class="font-medium text-slate-800">{{ $userName($activity->user) }}</span> {{ $activity->describe() }} <span class="text-xs text-slate-400">· {{ ClinicTime::format($activity->created_at) }}</span></p>
                        </li>
                    @endforeach
                </ol>
            </section>
        </div>

        {{-- Detalhes --}}
        <aside class="rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm lg:self-start">
            <h4 class="font-semibold text-slate-900">Detalhes da tarefa</h4>
            <dl class="mt-3 space-y-3">
                @foreach ([
                    ['Status', 'task_column_id', 'select', $columns->pluck('name', 'id')->all(), $task->task_column_id, $task->column->name],
                    ['Prioridade', 'priority', 'select', $priorities, $task->priority->value, $task->priority->label()],
                    ['Responsável', 'assignee_id', 'select', ['' => 'Sem responsável'] + $users->pluck('name', 'id')->all(), $task->assignee_id, $task->assignee?->name ?? 'Sem responsável'],
                    ['Data de início', 'starts_on', 'date', null, $task->starts_on?->format('Y-m-d'), $task->starts_on?->format('d/m/Y') ?? '—'],
                    ['Data de conclusão', 'due_on', 'date', null, $task->due_on?->format('Y-m-d'), $task->due_on?->format('d/m/Y') ?? '—'],
                ] as [$label, $name, $type, $options, $current, $display])
                    @php $current = $value($name, $current); @endphp
                    <div class="grid grid-cols-[6.5rem_minmax(0,1fr)] items-center gap-2">
                        <dt class="text-xs text-slate-500"><label for="task-edit-{{ $name }}">{{ $label }}</label></dt>
                        <dd>
                            @if (! $canUpdate)
                                <span class="font-medium text-slate-800">{{ $display }}</span>
                            @elseif ($type === 'select')
                                <select id="task-edit-{{ $name }}" form="task-edit-form" name="{{ $name }}" @class([$fieldClass, 'input-error' => $editing && $errors->has($name)])>
                                    @foreach ($options as $optionValue => $optionLabel)
                                        <option value="{{ $optionValue }}" @selected((string) $current === (string) $optionValue)>{{ $optionLabel }}</option>
                                    @endforeach
                                </select>
                            @else
                                <input id="task-edit-{{ $name }}" form="task-edit-form" name="{{ $name }}" type="date" value="{{ $current }}" @class([$fieldClass, 'input-error' => $editing && $errors->has($name)])>
                            @endif
                            @if ($editing)
                                @error($name)
                                    <p class="mt-1 text-xs text-red-700">{{ $message }}</p>
                                @enderror
                            @endif
                        </dd>
                    </div>
                @endforeach
                @if ($labels->isNotEmpty())
                    <div class="grid grid-cols-[6.5rem_minmax(0,1fr)] items-start gap-2">
                        <dt class="pt-1 text-xs text-slate-500">Etiquetas</dt>
                        <dd class="flex flex-wrap gap-1.5">
                            @foreach ($labels as $label)
                                @if ($canUpdate)
                                    <label class="flex cursor-pointer items-center gap-1">
                                        <input type="checkbox" form="task-edit-form" name="labels[]" value="{{ $label->id }}" class="size-3.5 rounded border-slate-300 accent-brand" @checked(in_array($label->id, $selectedLabels, true))>
                                        <span class="badge {{ $label->badgeClasses() }}">{{ $label->name }}</span>
                                    </label>
                                @elseif ($task->labels->contains($label))
                                    <span class="badge {{ $label->badgeClasses() }}">{{ $label->name }}</span>
                                @endif
                            @endforeach
                            @if (! $canUpdate && $task->labels->isEmpty())
                                <span class="text-slate-500">—</span>
                            @endif
                        </dd>
                    </div>
                @endif
            </dl>

            @if ($canUpdate)
                <div class="mt-4 border-t border-slate-200 pt-4">
                    <label for="task-edit-description" class="text-xs text-slate-500">Descrição completa</label>
                    <textarea id="task-edit-description" form="task-edit-form" name="description" rows="5" maxlength="5000" @class(['input mt-1 text-sm', 'input-error' => $editing && $errors->has('description')])>{{ $value('description', $task->description) }}</textarea>
                    <p class="mt-1 text-xs text-slate-400">Não escreva dados de pacientes: o quadro é visto por toda a equipe.</p>
                    @if ($editing)
                        @error('description')
                            <p class="mt-1 text-xs text-red-700">{{ $message }}</p>
                        @enderror
                    @endif
                </div>
            @endif

            <div class="mt-4 border-t border-slate-200 pt-4">
                <p class="text-xs text-slate-500">Criado por</p>
                <div class="mt-1.5 flex items-center gap-2">
                    <span class="{{ $avatar }}" aria-hidden="true">{{ $initials($task->creator) }}</span>
                    <div class="min-w-0 leading-tight">
                        <p class="truncate font-medium text-slate-800">{{ $userName($task->creator) }}</p>
                        <p class="text-xs text-slate-500">{{ ClinicTime::format($task->created_at, 'd/m/Y \à\s H:i') }}</p>
                    </div>
                </div>
            </div>
        </aside>
    </div>

    <div class="sticky bottom-0 -mx-5 -mb-4 mt-6 flex flex-wrap items-center justify-between gap-2 rounded-b-xl border-t border-slate-200 bg-white px-5 py-3">
        <div>
            @if ($canDelete)
                <form method="POST" action="{{ route('tasks.destroy', $task) }}" data-confirm="Excluir a tarefa, com subtarefas, comentários e anexos? Não dá para desfazer.">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn bg-red-50 text-red-700 hover:bg-red-100">Excluir</button>
                </form>
            @endif
        </div>
        <div class="flex items-center gap-2">
            <button type="button" class="btn btn-secondary" data-dialog-close>{{ $canUpdate ? 'Cancelar' : 'Fechar' }}</button>
            @if ($canUpdate)
                <button type="submit" form="task-edit-form" class="btn btn-primary">Salvar alterações</button>
            @endif
        </div>
    </div>
</x-dialog>
