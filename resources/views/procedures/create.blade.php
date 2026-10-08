<x-layouts.app title="Novo procedimento">
    <div class="mx-auto max-w-3xl">
        <a href="{{ route('procedures.index') }}" class="text-sm text-slate-600 hover:underline">← Procedimentos</a>
        <h1 class="mt-1 text-xl font-semibold tracking-tight">Novo procedimento</h1>

        <form method="POST" action="{{ route('procedures.store') }}" class="mt-6 space-y-6" novalidate>
            @include('procedures._form')
        </form>
    </div>
</x-layouts.app>
