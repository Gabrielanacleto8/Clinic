@php
    // Iniciado na agenda ou no Início, o registro continua "dentro" daquele módulo.
    $back = $origin ? ['url' => $origin->url($appointment), 'label' => $origin->label()] : ['url' => route('patients.show', $patient), 'label' => $patient->name];
@endphp
<x-layouts.app :title="'Registrar atendimento · '.$patient->name" :section="$origin?->menuRoute()">
    <a href="{{ $back['url'] }}" class="text-sm text-slate-600 hover:underline">← {{ $back['label'] }}</a>
    <h1 class="mt-1 text-xl font-semibold tracking-tight">Registrar atendimento</h1>
    <p class="mt-1 text-sm text-slate-600">Ao registrar, o estoque é baixado e o financeiro é lançado. Fotos e documentos entram depois, no próprio atendimento.</p>

    <form method="POST" action="{{ route('patients.treatments.store', $patient) }}" class="mt-6 space-y-6" novalidate
          data-confirm="{{ App\Services\TreatmentFormOptions::CONFIRMATION }}">
        @if ($origin)
            <input type="hidden" name="origem" value="{{ $origin->value }}">
        @endif
        @include('treatments._form', ['cancelUrl' => $back['url']])
    </form>
</x-layouts.app>
