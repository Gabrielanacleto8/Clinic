@php
    $progress = $task->progress();
    $canMove = auth()->user()->can('update', $task);
    $dueLabel = $task->due_on?->format('d/m');
    $initials = $task->assignee ? mb_strtoupper(mb_substr($task->assignee->name, 0, 1)) : null;
@endphp
{{-- Cartão do quadro: clicar abre o painel na mesma página (?tarefa=ID); arrastar move (app.js). --}}
<li class="group/card rounded-lg border border-slate-200 bg-white shadow-xs transition-shadow hover:shadow-md" data-task-card="{{ $task->id }}"
    data-move-url="{{ route('tasks.move', $task) }}" @if ($canMove) draggable="true" @endif>
    <a href="{{ route('tasks.index', [...array_filter($filters), 'tarefa' => $task->id]) }}" class="block p-3 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-700">
        @if ($task->labels->isNotEmpty())
            <ul class="mb-1.5 flex flex-wrap gap-1" aria-label="Etiquetas">
                @foreach ($task->labels as $label)
                    <li class="badge {{ $label->badgeClasses() }} text-[11px]">{{ $label->name }}</li>
                @endforeach
            </ul>
        @endif
        <p class="text-sm font-medium text-slate-900">{{ $task->title }}</p>
        <div class="mt-2 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-slate-500">
            <span class="badge {{ $task->priority->badgeClasses() }}">{{ $task->priority->label() }}</span>
            @if ($dueLabel)
                <span @class(['tabular-nums', 'font-medium text-red-700' => $task->isOverdue()])>{{ $task->isOverdue() ? 'Venceu' : 'Prazo' }} {{ $dueLabel }}</span>
            @endif
            @if ($task->subtasks_count > 0)
                <span class="tabular-nums" title="Subtarefas concluídas">{{ $task->completed_subtasks_count }}/{{ $task->subtasks_count }}</span>
            @endif
            @if ($task->comments_count > 0)
                <span class="tabular-nums" title="Comentários">{{ $task->comments_count }} <x-icon name="message" class="inline size-3.5 align-[-2px]" /></span>
            @endif
            @if ($task->attachments_count > 0)
                <span class="tabular-nums" title="Anexos">{{ $task->attachments_count }} <x-icon name="paperclip" class="inline size-3.5 align-[-2px]" /></span>
            @endif
            @if ($initials)
                <span class="ml-auto flex size-6 items-center justify-center rounded-full bg-slate-200 text-[11px] font-semibold text-slate-700" title="{{ $task->assignee->name }}" aria-label="Responsável: {{ $task->assignee->name }}">{{ $initials }}</span>
            @endif
        </div>
        @if ($progress !== null)
            <progress class="bar mt-2 text-slate-700" max="100" value="{{ $progress }}" aria-label="Progresso: {{ $progress }}%">{{ $progress }}%</progress>
        @endif
    </a>
</li>
