@props(['title' => null, 'back' => null])
{{-- Página para imprimir ou salvar em PDF: sem menu, fundo branco, barra de ações escondida na impressão. --}}
<!DOCTYPE html>
<html lang="pt-BR">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex, nofollow">
        <title>{{ $title ? $title.' · ' : '' }}{{ config('app.name') }}</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="bg-white text-slate-900 antialiased">
        <div class="sticky top-0 z-10 flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 bg-white px-4 py-3 print:hidden">
            @if ($back)
                <a href="{{ $back }}" class="text-sm text-slate-600 hover:underline">← Voltar</a>
            @else
                <span></span>
            @endif
            <button type="button" class="btn btn-primary" data-print>Imprimir ou salvar em PDF</button>
        </div>
        <main class="mx-auto max-w-4xl px-4 py-6 text-sm print:max-w-none print:px-0 print:py-0">
            {{ $slot }}
        </main>
    </body>
</html>
