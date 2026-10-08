<x-layouts.app :title="'Novo atendimento · '.$patient->name">
    <a href="{{ route('patients.show', $patient) }}" class="text-sm text-slate-600 hover:underline">← {{ $patient->name }}</a>
    <h1 class="mt-1 text-xl font-semibold tracking-tight">Novo atendimento</h1>
    <p class="mt-1 text-sm text-slate-600">O atendimento fica em andamento até ser finalizado. Fotos, documentos e a cobrança vêm depois de salvar.</p>

    <form method="POST" action="{{ route('patients.treatments.store', $patient) }}" class="mt-6 space-y-6" novalidate>
        @include('treatments._form')
    </form>
</x-layouts.app>
