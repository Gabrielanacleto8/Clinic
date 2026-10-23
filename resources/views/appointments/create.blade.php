<x-layouts.app title="Novo agendamento">
    <div class="mx-auto max-w-3xl">
        <a href="{{ route('agenda.index') }}" class="text-sm text-slate-600 hover:underline">← Agenda</a>
        <h1 class="mt-1 text-xl font-semibold tracking-tight">Novo agendamento</h1>

        <form method="POST" action="{{ route('appointments.store') }}" class="mt-6 space-y-6" novalidate>
            @include('appointments._form')
        </form>
    </div>
</x-layouts.app>
