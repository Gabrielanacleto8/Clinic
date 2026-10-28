@props(['active', 'subtitle' => null])
@php
    // Permissões só aparecem para quem pode alterá-las: hoje, o administrador.
    $tabs = array_filter([
        'usuarios' => ['Usuários', route('users.index')],
        'permissoes' => auth()->user()->can('manage-permissions') ? ['Permissões', route('users.permissions.edit')] : null,
    ]);

    $tabClass = fn (bool $current) => 'rounded-lg px-3 py-1.5 text-sm font-medium whitespace-nowrap '.($current ? 'bg-brand text-on-brand' : 'text-slate-700 hover:bg-slate-100');
@endphp
{{-- Moldura das abas de usuários: título, ações e navegação. --}}
<x-layouts.app title="Usuários">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold tracking-tight">Usuários</h1>
            @if ($subtitle)
                <p class="mt-1 text-sm text-slate-600">{{ $subtitle }}</p>
            @endif
        </div>
        @isset($actions)
            <div class="flex flex-wrap items-center gap-2">{{ $actions }}</div>
        @endisset
    </div>

    <nav class="mt-4 flex gap-1 relative overflow-x-auto rounded-xl border border-slate-200 bg-white p-1" aria-label="Seções de usuários" data-tabs>
        @foreach ($tabs as $key => [$label, $url])
            <a href="{{ $url }}" class="{{ $tabClass($active === $key) }}" @if ($active === $key) aria-current="page" @endif>{{ $label }}</a>
        @endforeach
    </nav>

    {{ $slot }}
</x-layouts.app>
