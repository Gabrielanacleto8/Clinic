<x-layouts.guest title="Entrar">
    <div class="mb-6 text-center">
        <h1 class="text-2xl font-semibold tracking-tight text-teal-800">{{ config('app.name') }}</h1>
        <p class="mt-1 text-sm text-slate-600">Acesse com seu e-mail e senha</p>
    </div>

    {{-- Ex.: sessão expirada por inatividade (ver bootstrap/app.php). --}}
    <x-flash />

    <form method="POST" action="{{ route('login') }}" class="card space-y-4 p-6" novalidate>
        @csrf

        <x-form.input name="email" label="E-mail" type="email" required autofocus autocomplete="username" />
        <x-form.input name="password" label="Senha" type="password" required autocomplete="current-password" />

        <div>
            <label class="flex items-center gap-2 text-sm text-slate-700">
                <input type="checkbox" name="remember" value="1" class="size-4 rounded border-slate-300 accent-teal-700" @checked(old('remember'))>
                Lembrar de mim neste dispositivo
            </label>
            <p class="mt-1 text-xs text-slate-500">Não marque em computadores compartilhados.</p>
        </div>

        <button type="submit" class="btn btn-primary w-full">Entrar</button>
    </form>

    <p class="mt-4 text-center text-xs text-slate-500">
        Esqueceu a senha? Peça uma nova ao administrador do sistema.
    </p>
</x-layouts.guest>
