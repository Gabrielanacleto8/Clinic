<x-layouts.app title="Alterar senha">
    <div class="mx-auto max-w-md">
        <h1 class="text-xl font-semibold tracking-tight">Alterar senha</h1>
        <p class="mt-1 text-sm text-slate-600">
            Mínimo de 8 caracteres, com letras maiúsculas e minúsculas e números.
        </p>

        <form method="POST" action="{{ route('password.update') }}" class="card mt-6 space-y-4 p-6" novalidate>
            @csrf
            @method('PUT')

            <x-form.input name="current_password" label="Senha atual" type="password" required autocomplete="current-password" />
            <x-form.input name="password" label="Nova senha" type="password" required autocomplete="new-password" />
            <x-form.input name="password_confirmation" label="Confirme a nova senha" type="password" required autocomplete="new-password" />

            <button type="submit" class="btn btn-primary w-full">Salvar nova senha</button>
        </form>
    </div>
</x-layouts.app>
