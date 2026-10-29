@php
    use App\Support\ClinicTime;

    $entry = $treatment->relationLoaded('financialEntry') ? $treatment->financialEntry : null;
    $attachments = collect([
        $treatment->photos_count > 0 ? $treatment->photos_count.' '.($treatment->photos_count === 1 ? 'foto' : 'fotos') : null,
        $treatment->documents_count > 0 ? $treatment->documents_count.' '.($treatment->documents_count === 1 ? 'documento' : 'documentos') : null,
    ])->filter()->implode(' e ');
@endphp
{{-- Conteúdo da janela de atendimento da lista, buscado por app.js ao clicar na linha. Sem layout. --}}
<div data-summary-content>
    <h3 class="text-lg font-semibold tracking-tight">{{ $treatment->procedure->name }}</h3>
    <p class="mt-1 text-sm text-slate-600">
        <a href="{{ route('patients.show', $patient) }}" class="font-medium text-teal-800 hover:underline">{{ $patient->name }}</a>
        · {{ ClinicTime::format($treatment->performed_at, 'd/m/Y') }} às {{ ClinicTime::format($treatment->performed_at, 'H:i') }}
        · {{ $treatment->professional->nameWithRegistration() }}
    </p>
    <p class="mt-1 text-xs text-slate-500">Registrado em {{ ClinicTime::format($treatment->created_at) }}.</p>
    @if ($treatment->isVoided())
        <p class="mt-3 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900" role="note">
            Registro anulado em {{ ClinicTime::format($treatment->voided_at) }}. Motivo: {{ $treatment->void_reason }}
        </p>
    @endif

    <x-health-alerts :anamnesis="$patient->anamnesis" class="mt-4" />

    @include('treatments._clinical', ['compact' => true])
    @include('treatments._products')
    @include('treatments._finance')

    <section class="card mt-6 p-6" aria-labelledby="resumo-evolucoes">
        <h2 id="resumo-evolucoes" class="text-sm font-semibold tracking-wide text-slate-500 uppercase">Evoluções e adendos</h2>
        @include('treatments._notes_list')
    </section>

    <div class="mt-6 flex flex-wrap items-center justify-between gap-3">
        <p class="text-sm text-slate-600">
            {{ $attachments !== '' ? ucfirst($attachments).' na página completa.' : 'Fotos, documentos e evoluções são adicionados na página completa.' }}
        </p>
        <div class="flex gap-2">
            <button type="button" class="btn btn-secondary" data-dialog-close>Fechar</button>
            <a href="{{ route('patients.treatments.show', [$patient, $treatment]) }}" class="btn btn-primary">Abrir página completa</a>
        </div>
    </div>
</div>
