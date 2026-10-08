<x-layouts.app title="Novo usuário">
    <div class="mx-auto max-w-xl">
        <h1 class="text-xl font-semibold tracking-tight">Novo usuário</h1>

        <form method="POST" action="{{ route('users.store') }}" class="mt-6 space-y-6" novalidate>
            @include('users._form')
        </form>
    </div>
</x-layouts.app>
