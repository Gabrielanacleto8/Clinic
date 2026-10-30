@props(['tabs', 'groups', 'active', 'label'])
@php
    // Abas principais (grupos) e subabas, como no financeiro e no estoque.
    // $tabs: chave => [rótulo, url], só as que o usuário pode abrir.
    // $groups: rótulo do grupo => chaves; some o grupo sem nenhuma aba visível.
    $groups = array_filter(array_map(
        fn (array $keys) => array_values(array_filter($keys, fn (string $key) => isset($tabs[$key]))),
        $groups,
    ));
    $activeGroup = array_key_first(array_filter($groups, fn (array $keys) => in_array($active, $keys, true)));

    // Abas principais sublinhadas e subabas neutras: a cor da marca fica para as ações.
    $groupClass = fn (bool $current) => '-mb-px border-b-2 px-0.5 pt-1 pb-2.5 text-sm font-medium whitespace-nowrap transition-colors '
        .($current ? 'border-slate-900 text-slate-900' : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-900');
    $subTabClass = fn (bool $current) => 'rounded-md px-2.5 py-1 text-sm transition-colors '
        .($current ? 'bg-slate-100 font-medium text-slate-900' : 'text-slate-500 hover:bg-slate-50 hover:text-slate-900');
@endphp
@if (count($tabs) > 1)
    {{-- Rótulos curtos cabem lado a lado até no celular; se não couberem, quebram linha em vez de rolar. --}}
    <nav class="mt-5 flex flex-wrap gap-x-4 border-b border-slate-200 sm:gap-x-7" aria-label="{{ $label }}">
        @foreach ($groups as $groupLabel => $keys)
            {{-- A aba principal leva à primeira subaba visível dela. Sem subabas, ela é a própria página. --}}
            <a href="{{ $tabs[$keys[0]][1] }}" class="{{ $groupClass($groupLabel === $activeGroup) }}" @if ($groupLabel === $activeGroup) aria-current="{{ count($keys) === 1 ? 'page' : 'true' }}" @endif>{{ $groupLabel }}</a>
        @endforeach
    </nav>

    @if ($activeGroup !== null && count($groups[$activeGroup]) > 1)
        <nav class="mt-3 flex flex-wrap gap-1" aria-label="Abas de {{ $activeGroup }}">
            @foreach ($groups[$activeGroup] as $key)
                <a href="{{ $tabs[$key][1] }}" class="{{ $subTabClass($active === $key) }}" @if ($active === $key) aria-current="page" @endif>{{ $tabs[$key][0] }}</a>
            @endforeach
        </nav>
    @endif
@endif
