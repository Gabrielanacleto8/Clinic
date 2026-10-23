<x-layouts.app title="Novo paciente">
    <a href="{{ route('patients.index') }}" class="text-sm text-slate-600 hover:underline">← Pacientes</a>
    <h1 class="mt-1 text-xl font-semibold tracking-tight">Novo paciente</h1>

    <form method="POST" action="{{ route('patients.store') }}" class="mt-6 space-y-6" novalidate>
        @include('patients._form')
    </form>
</x-layouts.app>
