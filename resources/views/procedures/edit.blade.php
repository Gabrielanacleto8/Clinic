<x-layouts.app :title="'Editar '.$procedure->name">
    <div class="mx-auto max-w-3xl">
        <a href="{{ route('procedures.index') }}" class="text-sm text-slate-600 hover:underline">← Procedimentos</a>
        <h1 class="mt-1 text-xl font-semibold tracking-tight">Editar procedimento</h1>

        <form method="POST" action="{{ route('procedures.update', $procedure) }}" class="mt-6 space-y-6" novalidate>
            @method('PUT')
            @include('procedures._form')
        </form>
    </div>
</x-layouts.app>
