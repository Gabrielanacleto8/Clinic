<x-layouts.app :title="'Editar '.$patient->name">
    <h1 class="text-xl font-semibold tracking-tight">Editar paciente</h1>
    <p class="mt-1 text-sm text-slate-600">{{ $patient->name }}</p>

    <form method="POST" action="{{ route('patients.update', $patient) }}" class="mt-6 space-y-6" novalidate>
        @method('PUT')
        @include('patients._form')
    </form>
</x-layouts.app>
