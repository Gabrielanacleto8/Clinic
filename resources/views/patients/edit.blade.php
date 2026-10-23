@php
    $pending = $financialSummary ? (float) $financialSummary->pending : 0.0;
    $archiveWarning = 'Arquivar o cadastro de '.$patient->name.'? Ele sai da listagem, mas não é apagado. Os agendamentos em aberto dele serão cancelados.'
        .($pending > 0 ? ' Há '.App\Support\LocalizedDecimal::money($pending).' pendentes no financeiro.' : '');
@endphp
<x-layouts.app :title="'Editar '.$patient->name">
    <a href="{{ route('patients.show', $patient) }}" class="text-sm text-slate-600 hover:underline">← {{ $patient->name }}</a>
    <h1 class="mt-1 text-xl font-semibold tracking-tight">Editar paciente</h1>

    <form method="POST" action="{{ route('patients.update', $patient) }}" class="mt-6 space-y-6" novalidate>
        @method('PUT')
        @include('patients._form')
    </form>

    @can('delete', $patient)
        <section class="card mt-10 border-red-200 p-6">
            <h2 class="text-sm font-semibold tracking-wide text-red-700 uppercase">Arquivar cadastro</h2>
            <p class="mt-2 text-sm text-slate-600">
                O paciente sai das listas e da busca, e os agendamentos em aberto são cancelados. Nada é apagado: o prontuário continua consultável e o cadastro pode ser restaurado.
                @if ($pending > 0)
                    <span class="block font-medium text-amber-800">Há {{ App\Support\LocalizedDecimal::money($pending) }} pendentes no financeiro.</span>
                @endif
            </p>
            <form method="POST" action="{{ route('patients.destroy', $patient) }}" class="mt-4" data-confirm="{{ $archiveWarning }}">
                @csrf
                @method('DELETE')
                <button type="submit" class="btn btn-danger">Arquivar paciente</button>
            </form>
        </section>
    @endcan
</x-layouts.app>
