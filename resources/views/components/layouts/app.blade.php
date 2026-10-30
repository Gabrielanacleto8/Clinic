@props(['title' => null, 'section' => null])
@php
    $user = auth()->user();

    $groups = App\Support\Navigation::for($user);

    // O cookie vem do navegador sem criptografia: só é comparado a um valor fixo.
    $collapsed = request()->cookie('sidebar') === 'collapsed';
    $collapseLabel = $collapsed ? 'Expandir menu' : 'Recolher menu';

    // Logo da clínica, se enviado: lido no disco, sem consulta ao banco.
    $logoVersion = app(App\Services\ClinicLogo::class)->version();

    // Conta do usuário: a página de perfil, ou a troca obrigatória de senha provisória.
    $accountRoute = $user->mustChangePassword() ? 'password.edit' : 'profile.show';
    $onAccount = request()->routeIs('profile.*', 'password.*');

    $words = preg_split('/\s+/', trim($user->name)) ?: [];
    $initials = mb_strtoupper(mb_substr($words[0] ?? '', 0, 1).(count($words) > 1 ? mb_substr(end($words), 0, 1) : ''));

    // "lg:rail:" vale só com a barra recolhida em telas largas: sobra o ícone, centralizado.
    $link = fn (bool $active) => 'flex w-full items-center gap-2.5 rounded-lg px-2.5 py-1.75 text-sm transition-colors lg:rail:justify-center lg:rail:px-0 '
        .'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-700 '
        .($active ? 'bg-slate-100 font-semibold text-slate-900' : 'text-slate-700 hover:bg-slate-100 hover:text-slate-900');

    // Botões só com ícone, no cabeçalho do conteúdo e na barra lateral.
    $iconButton = 'btn px-2 text-slate-500 hover:bg-slate-200/60 hover:text-slate-900 focus-visible:outline-slate-700';
@endphp
<!DOCTYPE html>
<html lang="pt-BR" data-sidebar="{{ $collapsed ? 'collapsed' : 'expanded' }}" data-theme="{{ App\Support\Theme::current(request()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta name="robots" content="noindex, nofollow">
        <title>{{ $title ? $title.' · ' : '' }}{{ config('app.name') }}</title>
        @include('components.layouts._install')
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-screen bg-canvas text-slate-900 antialiased">
        <a href="#conteudo" class="sr-only rounded-lg bg-white px-4 py-2 text-sm font-medium text-slate-800 shadow focus:not-sr-only focus:fixed focus:top-3 focus:left-3 focus:z-50">
            Pular para o conteúdo
        </a>

        <div class="fixed inset-0 z-30 hidden bg-black/40 max-lg:data-[open=true]:block" data-sidebar-backdrop data-open="false"></div>

        {{-- Telas pequenas: menu deslizante. Ao abrir, só o deslocamento é animado
             e a visibilidade muda na hora, para o menu já poder receber o foco do
             teclado. Telas largas: barra fixa, que recolhe a uma faixa de ícones.
             Nos dois casos é um painel arredondado, solto da borda como o conteúdo. --}}
        <aside id="sidebar" data-open="false" aria-label="Menu lateral"
               class="fixed inset-y-2 left-2 z-40 flex w-60 flex-col rounded-xl border border-slate-200 bg-white shadow-xs
                      transition-[translate,visibility,width] duration-200 motion-reduce:transition-none
                      max-lg:invisible max-lg:-translate-x-full max-lg:shadow-xl
                      max-lg:data-[open=true]:visible max-lg:data-[open=true]:translate-x-0 max-lg:data-[open=true]:transition-[translate]
                      lg:rail:w-16">
            <div class="flex items-center justify-between gap-2 px-3.5 pt-3.5 pb-2.5 lg:rail:justify-center lg:rail:px-2">
                <a href="{{ route('home') }}" class="flex min-w-0 items-center gap-3 rounded-xl focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-700" title="{{ config('app.name') }}">
                    @if ($logoVersion)
                        <img src="{{ route('clinic.logo', ['v' => $logoVersion]) }}" alt="" class="size-9 shrink-0 rounded-xl bg-white object-contain">
                    @else
                        <span class="flex size-9 shrink-0 items-center justify-center rounded-xl bg-slate-800 text-base font-bold text-white shadow-sm" aria-hidden="true">{{ mb_strtoupper(mb_substr(config('app.name'), 0, 1)) }}</span>
                    @endif
                    <span class="truncate text-lg font-bold tracking-tight lg:rail:sr-only">{{ config('app.name') }}</span>
                </a>

                <button type="button" class="{{ $iconButton }} lg:hidden" data-sidebar-close aria-label="Fechar menu">
                    <x-icon name="close" />
                </button>
            </div>

            <nav class="flex-1 space-y-2 overflow-x-hidden overflow-y-auto px-2.5 py-1.5" aria-label="Módulos do sistema">
                @foreach ($groups as $groupLabel => $items)
                    {{-- Cada grupo abre e fecha pelo título, e a partir do segundo uma linha
                         o separa do anterior. Recolhida, sobra só a linha. --}}
                    <details @class(['group/menu', 'border-t border-slate-200 pt-2' => ! $loop->first]) open>
                        <summary class="flex cursor-pointer items-center justify-between rounded-lg px-3 py-1.5 text-xs whitespace-nowrap text-slate-500 select-none
                                        hover:text-slate-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-700 lg:rail:hidden">
                            {{ $groupLabel }}
                            <x-icon name="chevron-down" class="size-3.5 transition-transform group-open/menu:rotate-180" />
                        </summary>
                        <ul class="mt-0.5 space-y-1 lg:rail:mt-0">
                            @foreach ($items as $item)
                                {{-- "section" marca o módulo de origem quando a tela pertence a outro (ex.: registrar atendimento a partir da agenda). --}}
                                @php $active = $section !== null ? $item['route'] === $section : request()->routeIs($item['active']); @endphp
                                <li>
                                    <a href="{{ route($item['route']) }}" class="{{ $link($active) }}" title="{{ $item['label'] }}" @if ($active) aria-current="page" @endif>
                                        <x-icon :name="$item['icon']" class="size-4.5 text-slate-800" />
                                        <span class="whitespace-nowrap lg:rail:sr-only">{{ $item['label'] }}</span>
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </details>
                @endforeach
            </nav>

            <div class="space-y-0.5 border-t border-slate-200 p-2.5">
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="{{ $link(false) }} cursor-pointer" title="Sair">
                        <x-icon name="logout" class="size-4.5 text-slate-800" />
                        <span class="whitespace-nowrap lg:rail:sr-only">Sair</span>
                    </button>
                </form>
                {{-- O menu não tem item de senha: a conta do usuário é aberta pelo próprio nome. --}}
                <a href="{{ route($accountRoute) }}" title="Meu perfil" @if ($onAccount) aria-current="page" @endif
                   class="flex items-center gap-3 rounded-xl px-2 py-1.5 transition-colors hover:bg-slate-100 aria-[current=page]:bg-slate-100 lg:rail:justify-center lg:rail:px-0
                          focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-700">
                    <span class="flex size-8 shrink-0 items-center justify-center rounded-xl bg-slate-200 text-xs font-semibold text-slate-700" aria-hidden="true">{{ $initials }}</span>
                    <span class="min-w-0 leading-tight lg:rail:sr-only">
                        <span class="block truncate text-sm font-medium">{{ $user->name }}</span>
                        <span class="block truncate text-xs text-slate-500">{{ $user->role->label() }}</span>
                        <span class="sr-only">, meu perfil</span>
                    </span>
                </a>
            </div>
        </aside>

        {{-- Conteúdo em um painel branco arredondado ao lado do menu, como na referência. --}}
        <div class="flex min-h-screen flex-col transition-[padding] duration-200 motion-reduce:transition-none lg:p-2 lg:pl-64 lg:rail:pl-20">
            <div class="flex flex-1 flex-col bg-white lg:rounded-xl lg:border lg:border-slate-200 lg:shadow-xs">
                <header class="sticky top-0 z-20 flex items-center gap-2 border-b border-slate-200 bg-white px-4 py-2.5 lg:static lg:rounded-t-xl">
                    <button type="button" class="{{ $iconButton }} lg:hidden" data-sidebar-open aria-controls="sidebar" aria-expanded="false" aria-label="Abrir menu">
                        <x-icon name="menu" />
                    </button>
                    {{-- Recolher a barra fica fora dela, no cabeçalho do conteúdo, no lugar do botão de menu do celular. --}}
                    <button type="button" class="{{ $iconButton }} max-lg:hidden" data-sidebar-collapse aria-controls="sidebar"
                            aria-expanded="{{ $collapsed ? 'false' : 'true' }}" aria-label="{{ $collapseLabel }}" title="{{ $collapseLabel }}">
                        <x-icon name="chevrons-left" class="size-4.5 transition-transform lg:rail:rotate-180" />
                    </button>
                    <span class="min-w-0 truncate text-sm font-medium">{{ $title ?? config('app.name') }}</span>

                    {{-- Quem está conectado, sempre à vista: no computador compartilhado da recepção,
                         evita agendar ou receber com a conta de outra pessoa. --}}
                    <div class="ml-auto flex shrink-0 items-center gap-1">
                        @if ($groups !== [])
                            {{-- Pesquisa geral: campo no computador, lupa que abre a página de pesquisa no celular. --}}
                            <form method="GET" action="{{ route('search') }}" role="search" class="relative max-md:hidden">
                                <label for="busca-topo" class="sr-only">Pesquisar</label>
                                <x-icon name="search" class="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-slate-400" />
                                <input id="busca-topo" name="q" type="search" maxlength="100" placeholder="Pesquisar…" data-global-search
                                       value="{{ request()->routeIs('search') ? request('q') : '' }}"
                                       class="input w-56 py-1.5 pr-8 pl-8 lg:w-72">
                                <kbd class="pointer-events-none absolute top-1/2 right-2 -translate-y-1/2 rounded border border-slate-200 px-1 text-[10px] text-slate-400" aria-hidden="true">/</kbd>
                            </form>
                            <a href="{{ route('search') }}" class="{{ $iconButton }} md:hidden" aria-label="Pesquisar" title="Pesquisar">
                                <x-icon name="search" />
                            </a>
                        @endif
                        <button type="button" class="{{ $iconButton }}" data-theme-toggle aria-label="Alternar modo escuro" title="Alternar modo escuro">
                            <x-icon name="moon" class="dark:hidden" />
                            <x-icon name="sun" class="hidden dark:block" />
                        </button>
                        <a href="{{ route($accountRoute) }}" @if ($onAccount) aria-current="page" @endif
                           class="flex shrink-0 items-center gap-2 rounded-lg px-2 py-1 text-sm text-slate-700 hover:bg-slate-100 aria-[current=page]:bg-slate-100" title="Meu perfil">
                            <span class="flex size-7 items-center justify-center rounded-lg bg-slate-200 text-xs font-semibold text-slate-700" aria-hidden="true">{{ $initials }}</span>
                            <span class="max-sm:sr-only">{{ Str::of($user->name)->before(' ') }}</span>
                            <span class="sr-only">, meu perfil</span>
                        </a>
                        <form method="POST" action="{{ route('logout') }}" class="max-lg:hidden">
                            @csrf
                            <button type="submit" class="btn btn-secondary px-2.5 py-1 text-xs">Sair</button>
                        </form>
                    </div>
                </header>

                {{-- O conteúdo ocupa a largura do painel; formulários limitam a própria largura. --}}
                <main id="conteudo" class="w-full flex-1 px-4 py-6 lg:px-8">
                    <x-flash />
                    <x-form-errors class="mb-6" />

                    {{ $slot }}
                </main>
            </div>
        </div>
    </body>
</html>
