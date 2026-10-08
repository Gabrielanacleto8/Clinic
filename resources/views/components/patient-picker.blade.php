@props(['patient' => null])
{{-- Seletor de paciente com busca. O comportamento está em resources/js/app.js. --}}
<div {{ $attributes->class('relative') }} data-patient-picker data-url="{{ route('patients.search') }}">
    <label for="patient_search" class="label">Paciente <span class="text-red-600" aria-hidden="true">*</span></label>
    <input type="hidden" name="patient_id" value="{{ $patient?->id }}" data-patient-id>
    <input id="patient_search" type="text" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="patient-options"
           autocomplete="off" value="{{ $patient?->name }}" placeholder="Digite o nome ou o CPF completo" data-patient-search
           @error('patient_id') aria-invalid="true" @enderror
           @class(['input', 'input-error' => $errors->has('patient_id')])>
    <ul id="patient-options" role="listbox" aria-label="Pacientes encontrados" hidden data-patient-options
        class="absolute z-10 mt-1 max-h-72 w-full overflow-y-auto rounded-lg border border-slate-200 bg-white py-1 shadow-lg"></ul>
    <p class="mt-1 text-xs text-slate-500" data-patient-status aria-live="polite">
        {{ $patient ? 'Paciente selecionado.' : 'Digite ao menos 2 letras para buscar.' }}
    </p>
    @error('patient_id')
        <p class="mt-1 text-sm text-red-700">{{ $message }}</p>
    @enderror
    @can('create', App\Models\Patient::class)
        <p class="mt-1 text-xs"><a href="{{ route('patients.create') }}" class="text-teal-800 hover:underline">Paciente novo? Cadastre antes de agendar.</a></p>
    @endcan
</div>
