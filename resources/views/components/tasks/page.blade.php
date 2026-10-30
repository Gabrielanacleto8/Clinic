@props(['active', 'title' => 'Tarefas', 'subtitle' => null])
@php
    use App\Models\Task;

    // Configurar o quadro é de quem gerencia tarefas.
    $tabs = array_filter([
        'quadro' => ['Quadro', route('tasks.index')],
        'colunas' => auth()->user()->can('configure', Task::class) ? ['Colunas', route('tasks.columns.index')] : null,
        'etiquetas' => auth()->user()->can('configure', Task::class) ? ['Etiquetas', route('tasks.labels.index')] : null,
    ]);
    $groups = ['Quadro' => ['quadro'], 'Configurar' => ['colunas', 'etiquetas']];
@endphp
{{-- Moldura das abas de tarefas: título, ações e navegação. --}}
<x-layouts.app :title="$title">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold tracking-tight">{{ $title }}</h1>
            @if ($subtitle)
                <p class="mt-1 text-sm text-slate-600">{{ $subtitle }}</p>
            @endif
        </div>
        @isset($actions)
            <div class="flex flex-wrap items-center gap-2">{{ $actions }}</div>
        @endisset
    </div>

    <x-section-tabs :tabs="$tabs" :groups="$groups" :active="$active" label="Seções de tarefas" />

    {{ $slot }}
</x-layouts.app>
