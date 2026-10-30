{{--
    Páginas de erro. Não consultam o banco nem a sessão: precisam abrir mesmo
    quando o erro é o próprio banco fora do ar. O estilo vem do CSS do Vite,
    sem <style> embutido, por causa da CSP.
--}}
<!DOCTYPE html>
<html lang="pt-BR">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex, nofollow">
        <title>@yield('title') · {{ config('app.name') }}</title>
        @vite(['resources/css/app.css'])
    </head>
    <body class="flex min-h-screen items-center justify-center bg-slate-100 px-4 py-10 text-slate-900 antialiased">
        <main class="w-full max-w-md">
            <div class="card p-6 text-center">
                <p class="text-sm font-semibold tracking-wide text-slate-800 uppercase">Erro @yield('code')</p>
                <h1 class="mt-2 text-xl font-semibold tracking-tight">@yield('title')</h1>
                <p class="mt-3 text-sm text-slate-600">@yield('message')</p>
                <div class="mt-6 flex justify-center">
                    <a href="{{ url('/') }}" class="btn btn-primary">Voltar ao início</a>
                </div>
            </div>
            <p class="mt-4 text-center text-xs text-slate-500">{{ config('app.name') }}</p>
        </main>
    </body>
</html>
