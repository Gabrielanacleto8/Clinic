{{-- Paginação no padrão visual do sistema, com os textos em português. --}}
@if ($paginator->hasPages())
    @php
        $page = 'inline-flex min-w-9 items-center justify-center rounded-lg border px-2.5 py-1.5 text-sm tabular-nums';
        $link = $page.' border-slate-200 bg-white text-slate-700 hover:bg-slate-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-teal-700';
        $current = $page.' border-teal-700 bg-teal-700 font-medium text-white';
        $disabled = $page.' border-slate-100 text-slate-400';
    @endphp
    <nav role="navigation" aria-label="Paginação" class="flex flex-wrap items-center justify-between gap-3">
        <p class="text-sm text-slate-600">
            @if ($paginator->total() > 0)
                Mostrando {{ $paginator->firstItem() }} a {{ $paginator->lastItem() }} de {{ $paginator->total() }}
            @endif
        </p>

        <ul class="flex flex-wrap items-center gap-1">
            <li>
                @if ($paginator->onFirstPage())
                    <span class="{{ $disabled }}" aria-disabled="true">‹<span class="sr-only"> Anterior</span></span>
                @else
                    <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="{{ $link }}" aria-label="Página anterior">‹</a>
                @endif
            </li>

            @foreach ($elements as $element)
                @if (is_string($element))
                    <li><span class="{{ $disabled }}" aria-disabled="true">{{ $element }}</span></li>
                @endif

                @if (is_array($element))
                    @foreach ($element as $number => $url)
                        <li>
                            @if ($number == $paginator->currentPage())
                                <span class="{{ $current }}" aria-current="page">{{ $number }}</span>
                            @else
                                <a href="{{ $url }}" class="{{ $link }}" aria-label="Ir para a página {{ $number }}">{{ $number }}</a>
                            @endif
                        </li>
                    @endforeach
                @endif
            @endforeach

            <li>
                @if ($paginator->hasMorePages())
                    <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="{{ $link }}" aria-label="Próxima página">›</a>
                @else
                    <span class="{{ $disabled }}" aria-disabled="true">›<span class="sr-only"> Próxima</span></span>
                @endif
            </li>
        </ul>
    </nav>
@endif
