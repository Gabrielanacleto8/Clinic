@php
    $anamnesis = $patient->anamnesis;
    $healthFields = [
        'Medicamentos em uso' => $anamnesis?->medications,
        'Condições de saúde' => $anamnesis?->health_conditions,
        'Procedimentos estéticos anteriores' => $anamnesis?->previous_procedures,
        'Observações' => $anamnesis?->notes,
    ];
@endphp

<section class="card mt-6 p-6" aria-labelledby="ficha-de-saude">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h2 id="ficha-de-saude" class="text-sm font-semibold tracking-wide text-slate-500 uppercase">Ficha de saúde</h2>
        @can('updateClinicalRecords', $patient)
            <a href="{{ route('patients.anamnesis.edit', $patient) }}" class="btn btn-secondary">{{ $anamnesis ? 'Editar ficha' : 'Preencher ficha' }}</a>
        @endcan
    </div>

    @if ($anamnesis)
        <x-health-alerts :anamnesis="$anamnesis" class="mt-4" />

        <dl class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
            @unless ($anamnesis->allergies)
                <div>
                    <dt class="text-xs text-slate-500">Alergias</dt>
                    <dd class="mt-0.5 text-sm">Nenhuma informada</dd>
                </div>
            @endunless
            @foreach ($healthFields as $label => $value)
                <div>
                    <dt class="text-xs text-slate-500">{{ $label }}</dt>
                    <dd class="mt-0.5 text-sm whitespace-pre-line">{{ $value ?: '—' }}</dd>
                </div>
            @endforeach
        </dl>
        <p class="mt-4 text-xs text-slate-500">
            Atualizada em {{ App\Support\ClinicTime::format($anamnesis->updated_at) }}
        </p>
    @else
        <p class="mt-4 text-sm text-slate-600">
            Ficha ainda não preenchida. Registre alergias, medicamentos e condições de saúde antes do primeiro procedimento.
        </p>
    @endif
</section>
