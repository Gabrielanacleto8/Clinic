@props(['active', 'subtitle' => null])
@php
    // Permissões só aparecem para quem pode alterá-las: hoje, o administrador.
    $tabs = array_filter([
        'usuarios' => ['Usuários', route('users.index')],
        'permissoes' => auth()->user()->can('manage-permissions') ? ['Permissões', route('users.permissions.edit')] : null,
    ]);

    // Abas sublinhadas, como as do financeiro.
    $tabClass = fn (bool $current) => '-mb-px border-b-2 px-0.5 pt-1 pb-2.5 text-sm font-medium whitespace-nowrap transition-colors '
        .($current ? 'border-slate-900 text-slate-900' : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-900');
@endphp
{{-- Moldura de Configurações › Usuários e permissões: título, ações e abas. --}}
<x-layouts.app title="Usuários e permissões">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold tracking-tight">Usuários e permissões</h1>
            @if ($subtitle)
                <p class="mt-1 text-sm text-slate-600">{{ $subtitle }}</p>
            @endif
        </div>
        @isset($actions)
            <div class="flex flex-wrap items-center gap-2">{{ $actions }}</div>
        @endisset
    </div>

    @if (count($tabs) > 1)
        <nav class="mt-5 flex flex-wrap gap-x-4 border-b border-slate-200 sm:gap-x-7" aria-label="Seções de usuários">
            @foreach ($tabs as $key => [$label, $url])
                <a href="{{ $url }}" class="{{ $tabClass($active === $key) }}" @if ($active === $key) aria-current="page" @endif>{{ $label }}</a>
            @endforeach
        </nav>
    @endif

    {{ $slot }}
</x-layouts.app>
