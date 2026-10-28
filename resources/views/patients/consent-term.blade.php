<x-layouts.print :title="'Termo de consentimento · '.$patient->name" :back="route('patients.show', $patient)">
    <x-clinic-header :profile="$clinic" document="Termo de consentimento" class="border-b border-slate-300 pb-4" />

    <h1 class="mt-6 text-center text-lg font-semibold">Termo de consentimento livre e esclarecido</h1>
    <p class="mt-1 text-center text-slate-600">{{ $procedure->name }}</p>

    <div class="mt-6 text-justify leading-relaxed whitespace-pre-line">{{ $text }}</div>

    @if ($patient->isMinor() || $patient->guardian_name)
        <p class="mt-6">
            Paciente menor de idade: assina o responsável legal{{ $patient->guardian_name ? ', '.$patient->guardian_name : '' }}{{ $patient->guardian_relationship ? ' ('.$patient->guardian_relationship.')' : '' }}.
        </p>
    @endif

    <p class="mt-10">Local e data: ________________________________________, ____/____/________</p>

    <div class="mt-16 grid grid-cols-1 gap-12 sm:grid-cols-2 print:grid-cols-2">
        <div class="border-t border-slate-500 pt-1 text-center text-xs">
            {{ $patient->isMinor() ? 'Responsável legal' : $patient->name }}
        </div>
        <div class="border-t border-slate-500 pt-1 text-center text-xs">Profissional responsável (nome e registro)</div>
    </div>
</x-layouts.print>
