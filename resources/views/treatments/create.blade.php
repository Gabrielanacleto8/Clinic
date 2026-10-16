<x-layouts.app :title="'Registrar atendimento · '.$patient->name">
    <a href="{{ route('patients.show', $patient) }}" class="text-sm text-slate-600 hover:underline">← {{ $patient->name }}</a>
    <h1 class="mt-1 text-xl font-semibold tracking-tight">Registrar atendimento</h1>
    <p class="mt-1 text-sm text-slate-600">Ao registrar, o estoque é baixado e o financeiro é lançado. Fotos e documentos entram depois, no próprio atendimento.</p>

    <form method="POST" action="{{ route('patients.treatments.store', $patient) }}" class="mt-6 space-y-6" novalidate
          data-confirm="{{ App\Services\TreatmentFormOptions::CONFIRMATION }}">
        @include('treatments._form')
    </form>
</x-layouts.app>
