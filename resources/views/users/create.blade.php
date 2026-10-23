<x-layouts.app title="Novo usuário">
    <div class="mx-auto max-w-xl">
        <a href="{{ route('users.index') }}" class="text-sm text-slate-600 hover:underline">← Usuários</a>
        <h1 class="mt-1 text-xl font-semibold tracking-tight">Novo usuário</h1>

        <form method="POST" action="{{ route('users.store') }}" class="mt-6 space-y-6" novalidate>
            @include('users._form')
        </form>
    </div>
</x-layouts.app>
