<x-layouts.app :title="'Editar '.$user->name">
    <div class="mx-auto max-w-xl">
        <a href="{{ route('users.index') }}" class="text-sm text-slate-600 hover:underline">← Usuários</a>
        <h1 class="mt-1 text-xl font-semibold tracking-tight">Editar usuário</h1>
        <p class="mt-1 text-sm text-slate-600">{{ $user->email }}</p>

        <form method="POST" action="{{ route('users.update', $user) }}" class="mt-6 space-y-6" novalidate>
            @method('PUT')
            @include('users._form')
        </form>
    </div>
</x-layouts.app>
