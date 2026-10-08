<x-layouts.app :title="'Editar atendimento · '.$patient->name">
    <a href="{{ route('patients.treatments.show', [$patient, $treatment]) }}" class="text-sm text-slate-600 hover:underline">← Atendimento</a>
    <h1 class="mt-1 text-xl font-semibold tracking-tight">Editar atendimento</h1>
    <p class="mt-1 text-sm text-slate-600">{{ $patient->name }} · atendimento em andamento. Depois de finalizado ele não pode mais ser editado.</p>

    <form method="POST" action="{{ route('patients.treatments.update', [$patient, $treatment]) }}" class="mt-6 space-y-6" novalidate>
        @method('PUT')
        @include('treatments._form')
    </form>
</x-layouts.app>
