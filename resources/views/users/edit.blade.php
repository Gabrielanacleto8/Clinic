<x-layouts.app :title="'Editar '.$user->name">
    <div class="mx-auto max-w-xl">
        <h1 class="text-xl font-semibold tracking-tight">Editar usuário</h1>
        <p class="mt-1 text-sm text-slate-600">{{ $user->email }}</p>

        <form method="POST" action="{{ route('users.update', $user) }}" class="mt-6 space-y-6" novalidate>
            @method('PUT')
            @include('users._form')
        </form>
    </div>
</x-layouts.app>
