@php
    $groupHeading = 'px-4 pt-4 pb-1 text-xs font-semibold tracking-wide text-slate-500 uppercase sm:px-6';
    $row = 'flex items-center justify-between gap-3 px-4 py-2.5 hover:bg-slate-50 sm:px-6';
    $empty = $ready && $modules === [] && ($patients?->isEmpty() ?? true) && ($procedures?->isEmpty() ?? true) && ($products?->isEmpty() ?? true);
@endphp
<x-layouts.app title="Pesquisa">
    <div class="mx-auto max-w-3xl">
        <h1 class="text-xl font-semibold tracking-tight">Pesquisa</h1>

        <form method="GET" action="{{ route('search') }}" class="mt-4 flex gap-2" role="search">
            <label for="busca-pagina" class="sr-only">Pesquisar</label>
            <input id="busca-pagina" name="q" type="search" value="{{ $term }}" maxlength="100" class="input" autofocus
                   placeholder="Paciente (nome, CPF completo ou telefone), procedimento, produto ou módulo">
            <button type="submit" class="btn btn-primary">Pesquisar</button>
        </form>

        @if (! $ready)
            <p class="mt-6 text-sm text-slate-600">Digite ao menos 2 letras. No computador, a tecla <kbd class="rounded border border-slate-300 px-1 text-xs">/</kbd> leva direto à pesquisa do topo.</p>
        @elseif ($empty)
            <div class="card mt-6 p-8 text-center">
                <p class="text-sm text-slate-600">Nada encontrado para “{{ $term }}”.</p>
                @can('create', App\Models\Patient::class)
                    <a href="{{ route('patients.create') }}" class="btn btn-primary mt-4">Cadastrar paciente</a>
                @endcan
            </div>
        @else
            <div class="card mt-6 divide-y divide-slate-100 overflow-hidden">
                @if ($modules !== [])
                    <section aria-labelledby="r-modulos">
                        <h2 id="r-modulos" class="{{ $groupHeading }}">Módulos</h2>
                        <ul>
                            @foreach ($modules as $item)
                                <li><a href="{{ route($item['route']) }}" class="{{ $row }}"><span class="flex items-center gap-2"><x-icon :name="$item['icon']" class="text-teal-600" />{{ $item['label'] }}</span></a></li>
                            @endforeach
                        </ul>
                    </section>
                @endif

                @if ($patients?->isNotEmpty())
                    <section aria-labelledby="r-pacientes">
                        <h2 id="r-pacientes" class="{{ $groupHeading }}">Pacientes</h2>
                        <ul>
                            @foreach ($patients as $patient)
                                <li>
                                    <a href="{{ route('patients.show', $patient) }}" class="{{ $row }}">
                                        <span class="font-medium text-teal-800">{{ $patient->name }}</span>
                                        <span class="text-xs text-slate-500 tabular-nums">{{ $patient->age() }} anos{{ $patient->phone ? ' · '.$patient->formattedPhone() : '' }}</span>
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                        @if ($patients->count() === 8)
                            <a href="{{ route('patients.index', ['q' => $term]) }}" class="block px-4 pb-3 text-sm text-teal-800 hover:underline sm:px-6">Ver todos os pacientes encontrados</a>
                        @endif
                    </section>
                @endif

                @if ($procedures?->isNotEmpty())
                    <section aria-labelledby="r-procedimentos">
                        <h2 id="r-procedimentos" class="{{ $groupHeading }}">Procedimentos</h2>
                        <ul>
                            @foreach ($procedures as $procedure)
                                <li>
                                    <a href="{{ route('procedures.index', ['q' => $procedure->name, 'situacao' => 'todos']) }}" class="{{ $row }}">
                                        <span class="font-medium text-teal-800">{{ $procedure->name }}</span>
                                        @unless ($procedure->is_active)<span class="badge bg-slate-100 text-slate-600">Inativo</span>@endunless
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </section>
                @endif

                @if ($products?->isNotEmpty())
                    <section aria-labelledby="r-produtos">
                        <h2 id="r-produtos" class="{{ $groupHeading }}">Produtos</h2>
                        <ul>
                            @foreach ($products as $product)
                                <li>
                                    <a href="{{ route('products.show', $product) }}" class="{{ $row }}">
                                        <span class="font-medium text-teal-800">{{ $product->name }}</span>
                                        @unless ($product->is_active)<span class="badge bg-slate-100 text-slate-600">Inativo</span>@endunless
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </section>
                @endif
            </div>
        @endif
    </div>
</x-layouts.app>
