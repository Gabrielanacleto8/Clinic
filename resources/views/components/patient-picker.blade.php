@props(['patient' => null, 'scope' => null, 'autofocus' => false, 'required' => true])
@php
    // Com "scope" (o id de um diálogo), o erro só vale se o envio partiu dele.
    $hasError = ($scope === null || old('_dialog') === $scope) && $errors->has('patient_id');
@endphp
{{-- Seletor de paciente com busca. O comportamento está em resources/js/app.js. --}}
<div {{ $attributes->class('relative') }} data-patient-picker data-url="{{ route('patients.search') }}">
    <label for="patient_search" class="label">Paciente @if ($required)<span class="text-red-600" aria-hidden="true">*</span>@else<span class="font-normal text-slate-500">(opcional)</span>@endif</label>
    <input type="hidden" name="patient_id" value="{{ $patient?->id }}" data-patient-id>
    <input id="patient_search" type="text" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="patient-options"
           autocomplete="off" value="{{ $patient?->name }}" placeholder="Nome, CPF completo ou telefone" data-patient-search
           @if ($autofocus) autofocus @endif
           @if ($hasError) aria-invalid="true" @endif
           @class(['input', 'input-error' => $hasError])>
    <ul id="patient-options" role="listbox" aria-label="Pacientes encontrados" hidden data-patient-options
        class="absolute z-10 mt-1 max-h-72 w-full overflow-y-auto rounded-lg border border-slate-200 bg-white py-1 shadow-lg"></ul>
    <p class="mt-1 text-xs text-slate-500" data-patient-status aria-live="polite">
        {{ $patient ? 'Paciente selecionado.' : 'Digite ao menos 2 letras para buscar.' }}
    </p>
    @if ($hasError)
        <p class="mt-1 text-sm text-red-700">{{ $errors->first('patient_id') }}</p>
    @endif
    @can('create', App\Models\Patient::class)
        <p class="mt-1 text-xs"><a href="{{ route('patients.create') }}" class="text-slate-800 hover:underline">Paciente novo? Cadastre primeiro.</a></p>
    @endcan
</div>
