<x-layouts.app title="Novo paciente">
    <h1 class="text-xl font-semibold tracking-tight">Novo paciente</h1>

    <form method="POST" action="{{ route('patients.store') }}" class="mt-6 space-y-6" novalidate>
        @include('patients._form')
    </form>
</x-layouts.app>
