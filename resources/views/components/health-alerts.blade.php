@props(['anamnesis', 'fillUrl' => null])
@php
    use App\Support\ClinicTime;

    // Avisos que o profissional precisa ver antes de qualquer procedimento.
    // Ficha não preenchida ou sem revisão há muito tempo também é aviso: o
    // silêncio não pode parecer "paciente sem contraindicação".
    $alerts = $anamnesis?->alerts() ?? [];
    $stale = $anamnesis?->isStale() ?? false;
    $classes = 'rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900';
@endphp
@if ($anamnesis === null)
    <div {{ $attributes->class($classes) }} role="note" data-health-alerts-static>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <p class="font-semibold">Ficha de saúde não preenchida</p>
                <p class="mt-1">Confira alergias, medicamentos e condições de saúde com o paciente antes do procedimento.</p>
            </div>
            @if ($fillUrl)
                <a href="{{ $fillUrl }}" class="btn btn-secondary shrink-0">Preencher ficha</a>
            @endif
        </div>
    </div>
@elseif ($alerts !== [] || $stale)
    <div {{ $attributes->class($classes) }} role="note" data-health-alerts-static>
        <p class="font-semibold">Atenção</p>
        <ul class="mt-1 space-y-1">
            @if ($stale)
                <li>Ficha de saúde sem revisão desde {{ ClinicTime::format($anamnesis->updated_at, 'd/m/Y') }}: confira com o paciente se algo mudou.</li>
            @endif
            @foreach ($alerts as $alert)
                <li>
                    @if ($alert['label'])<span class="font-medium">{{ $alert['label'] }}:</span>@endif
                    <span class="whitespace-pre-line">{{ $alert['text'] }}</span>
                </li>
            @endforeach
        </ul>
    </div>
@endif
