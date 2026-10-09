@props(['title' => null, 'wide' => false])
@php
    $user = auth()->user();

    // Módulos do sistema, agrupados. Para incluir um novo, acrescente um item
    // aqui: ele só aparece para quem tem a permissão indicada em "can".
    $groups = [
        'Módulos' => [
            ['label' => 'Início', 'route' => 'home', 'active' => 'home', 'icon' => 'home', 'can' => null],
            ['label' => 'Agenda', 'route' => 'agenda.index', 'active' => ['agenda.*', 'appointments.*'], 'icon' => 'calendar', 'can' => ['viewAny', App\Models\Appointment::class]],
            ['label' => 'Atendimentos', 'route' => 'treatments.index', 'active' => 'treatments.index', 'icon' => 'activity', 'can' => ['viewQueue', App\Models\Treatment::class]],
            ['label' => 'Pacientes', 'route' => 'patients.index', 'active' => 'patients.*', 'icon' => 'patients', 'can' => ['viewAny', App\Models\Patient::class]],
            ['label' => 'Procedimentos', 'route' => 'procedures.index', 'active' => 'procedures.*', 'icon' => 'clipboard', 'can' => ['viewAny', App\Models\Procedure::class]],
            ['label' => 'Estoque', 'route' => 'products.index', 'active' => ['products.*', 'stock.*'], 'icon' => 'box', 'can' => ['viewAny', App\Models\Product::class]],
            ['label' => 'Financeiro', 'route' => 'finance.index', 'active' => 'finance.*', 'icon' => 'dollar', 'can' => ['viewAny', App\Models\FinancialEntry::class]],
        ],
        'Administração' => [
            ['label' => 'Usuários', 'route' => 'users.index', 'active' => 'users.*', 'icon' => 'shield', 'can' => ['viewAny', App\Models\User::class]],
            ['label' => 'Permissões', 'route' => 'permissions.edit', 'active' => 'permissions.*', 'icon' => 'lock', 'can' => ['manage-permissions']],
        ],
    ];

    // Com senha provisória nenhum módulo é acessível, então nenhum é listado.
    $groups = $user->mustChangePassword() ? [] : array_filter(array_map(
        // "can" nulo: item visível a qualquer usuário autenticado (o Início).
        fn (array $items) => array_filter($items, fn (array $item) => $item['can'] === null || $user->can(...$item['can'])),
        $groups,
    ));

    // O cookie vem do navegador sem criptografia: só é comparado a um valor fixo.
    $collapsed = request()->cookie('sidebar') === 'collapsed';
    $collapseLabel = $collapsed ? 'Expandir menu' : 'Recolher menu';

    $words = preg_split('/\s+/', trim($user->name)) ?: [];
    $initials = mb_strtoupper(mb_substr($words[0] ?? '', 0, 1).(count($words) > 1 ? mb_substr(end($words), 0, 1) : ''));

    // "lg:rail:" vale só com a barra recolhida em telas largas: sobra o ícone, centralizado.
    $link = fn (bool $active) => 'flex w-full items-center gap-2.5 rounded-lg px-2.5 py-2 text-sm transition-colors lg:rail:justify-center lg:rail:px-0 '
        .'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-teal-700 '
        .($active ? 'bg-slate-200/70 font-medium text-slate-900' : 'text-slate-700 hover:bg-slate-200/60 hover:text-slate-900');

    // Botões só com ícone, no cabeçalho do conteúdo e na barra lateral.
    $iconButton = 'btn px-2 text-slate-500 hover:bg-slate-200/60 hover:text-slate-900 focus-visible:outline-teal-700';
@endphp
<!DOCTYPE html>
<html lang="pt-BR" data-sidebar="{{ $collapsed ? 'collapsed' : 'expanded' }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex, nofollow">
        <title>{{ $title ? $title.' · ' : '' }}{{ config('app.name') }}</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-screen bg-slate-100 text-slate-900 antialiased">
        <a href="#conteudo" class="sr-only rounded-lg bg-white px-4 py-2 text-sm font-medium text-teal-800 shadow focus:not-sr-only focus:fixed focus:top-3 focus:left-3 focus:z-50">
            Pular para o conteúdo
        </a>

        <div class="fixed inset-0 z-30 hidden bg-slate-900/40 max-lg:data-[open=true]:block" data-sidebar-backdrop data-open="false"></div>

        {{-- Telas pequenas: menu deslizante. Ao abrir, só o deslocamento é animado
             e a visibilidade muda na hora, para o menu já poder receber o foco do
             teclado. Telas largas: barra fixa, que recolhe a uma faixa de ícones. --}}
        <aside id="sidebar" data-open="false" aria-label="Menu lateral"
               class="fixed inset-y-0 left-0 z-40 flex w-64 flex-col bg-slate-100
                      transition-[translate,visibility,width] duration-200 motion-reduce:transition-none
                      max-lg:invisible max-lg:-translate-x-full max-lg:border-r max-lg:border-slate-200 max-lg:shadow-xl
                      max-lg:data-[open=true]:visible max-lg:data-[open=true]:translate-x-0 max-lg:data-[open=true]:transition-[translate]
                      lg:rail:w-16">
            <div class="flex items-center justify-between gap-2 p-3 lg:rail:justify-center lg:rail:px-2">
                <a href="{{ route('home') }}" class="flex min-w-0 items-center gap-2.5 rounded-lg p-1 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-teal-700" title="{{ config('app.name') }}">
                    <span class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-teal-700 text-sm font-bold text-white" aria-hidden="true">{{ mb_strtoupper(mb_substr(config('app.name'), 0, 1)) }}</span>
                    <span class="truncate text-base font-semibold tracking-tight lg:rail:sr-only">{{ config('app.name') }}</span>
                </a>

                <button type="button" class="{{ $iconButton }} lg:hidden" data-sidebar-close aria-label="Fechar menu">
                    <x-icon name="close" />
                </button>
            </div>

            <nav class="flex-1 space-y-2 overflow-x-hidden overflow-y-auto px-3 py-1 lg:rail:space-y-3" aria-label="Módulos do sistema">
                @foreach ($groups as $groupLabel => $items)
                    {{-- Cada grupo abre e fecha pelo título. Recolhida, o título some e
                         os grupos ficam separados só pelo espaçamento. --}}
                    <details class="group/menu" open>
                        <summary class="flex cursor-pointer items-center justify-between rounded-lg px-2 py-1.5 text-xs font-medium whitespace-nowrap text-slate-500 select-none
                                        hover:text-slate-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-teal-700 lg:rail:hidden">
                            {{ $groupLabel }}
                            <x-icon name="chevron-down" class="size-4 transition-transform group-open/menu:rotate-180" />
                        </summary>
                        <ul class="mt-0.5 space-y-0.5 lg:rail:mt-0">
                            @foreach ($items as $item)
                                @php $active = request()->routeIs($item['active']); @endphp
                                <li>
                                    <a href="{{ route($item['route']) }}" class="{{ $link($active) }}" title="{{ $item['label'] }}" @if ($active) aria-current="page" @endif>
                                        <x-icon :name="$item['icon']" class="text-teal-600" />
                                        <span class="whitespace-nowrap lg:rail:sr-only">{{ $item['label'] }}</span>
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </details>
                @endforeach
            </nav>

            <div class="space-y-0.5 p-3">
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="{{ $link(false) }} cursor-pointer" title="Sair">
                        <x-icon name="logout" class="text-teal-600" />
                        <span class="whitespace-nowrap lg:rail:sr-only">Sair</span>
                    </button>
                </form>
                {{-- O menu não tem item de senha: a conta do usuário é aberta pelo próprio nome. --}}
                <a href="{{ route('password.edit') }}" title="Minha conta" @if (request()->routeIs('password.*')) aria-current="page" @endif
                   class="mt-2 flex items-center gap-2.5 rounded-lg px-1 py-1.5 transition-colors hover:bg-slate-200/60 lg:rail:justify-center lg:rail:px-0
                          focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-teal-700">
                    <span class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-slate-200 text-xs font-semibold text-slate-700" aria-hidden="true">{{ $initials }}</span>
                    <span class="min-w-0 leading-tight lg:rail:sr-only">
                        <span class="block truncate text-sm font-medium">{{ $user->name }}</span>
                        <span class="block truncate text-xs text-slate-500">{{ $user->role->label() }}</span>
                        <span class="sr-only">, minha conta</span>
                    </span>
                </a>
            </div>
        </aside>

        {{-- Conteúdo em um painel branco arredondado ao lado do menu, como na referência. --}}
        <div class="flex min-h-screen flex-col transition-[padding] duration-200 motion-reduce:transition-none lg:p-2 lg:pl-64 lg:rail:pl-16">
            <div class="flex flex-1 flex-col bg-white lg:rounded-xl lg:border lg:border-slate-200 lg:shadow-xs">
                <header class="sticky top-0 z-20 flex items-center gap-2 border-b border-slate-200 bg-white px-4 py-2.5 lg:static lg:rounded-t-xl">
                    <button type="button" class="{{ $iconButton }} lg:hidden" data-sidebar-open aria-controls="sidebar" aria-expanded="false" aria-label="Abrir menu">
                        <x-icon name="menu" />
                    </button>
                    <button type="button" class="{{ $iconButton }} max-lg:hidden"
                            data-sidebar-collapse aria-controls="sidebar" aria-expanded="{{ $collapsed ? 'false' : 'true' }}"
                            aria-label="{{ $collapseLabel }}" title="{{ $collapseLabel }}">
                        <x-icon name="sidebar" />
                    </button>
                    <span class="truncate text-sm font-medium">{{ $title ?? config('app.name') }}</span>
                </header>

                {{-- "wide" libera a largura para telas que aproveitam o monitor inteiro, como a agenda. --}}
                <main id="conteudo" @class(['w-full flex-1 px-4 py-6 lg:px-6', 'max-w-6xl' => ! $wide, 'max-w-[96rem]' => $wide])>
                    <x-flash />

                    {{ $slot }}
                </main>
            </div>
        </div>
    </body>
</html>
