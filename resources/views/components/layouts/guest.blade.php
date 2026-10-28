@props(['title' => null])
<!DOCTYPE html>
<html lang="pt-BR" data-theme="{{ App\Support\Theme::current(request()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex, nofollow">
        <title>{{ $title ? $title.' · ' : '' }}{{ config('app.name') }}</title>
        @include('components.layouts._install')
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="flex min-h-screen items-center justify-center bg-canvas px-4 py-10 text-slate-900 antialiased">
        <main class="w-full max-w-sm">
            {{ $slot }}
        </main>
    </body>
</html>
