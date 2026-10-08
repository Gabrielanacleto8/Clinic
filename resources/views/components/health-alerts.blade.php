@props(['anamnesis'])
{{-- Avisos que o profissional precisa ver antes de qualquer procedimento. --}}
@if ($anamnesis?->allergies || $anamnesis?->pregnant_or_lactating)
    <div {{ $attributes->class('rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900') }} role="note">
        <p class="font-semibold">Atenção</p>
        <ul class="mt-1 space-y-1">
            @if ($anamnesis->pregnant_or_lactating)
                <li>Gestante ou lactante.</li>
            @endif
            @if ($anamnesis->allergies)
                <li><span class="font-medium">Alergias:</span> <span class="whitespace-pre-line">{{ $anamnesis->allergies }}</span></li>
            @endif
        </ul>
    </div>
@endif
