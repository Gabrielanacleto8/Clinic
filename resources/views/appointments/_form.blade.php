@php
    use App\Support\ClinicTime;
    use App\Support\Duration;

    // Vindo da ficha do paciente ("Agendar"), cancelar volta para ela.
    $cancelUrl = $appointment->exists
        ? route('agenda.index', ['data' => $appointment->localDay()]).'#dia'
        : ($fromPatient ?? false ? route('patients.show', $patient) : route('agenda.index'));
@endphp
@csrf

<section class="card p-6">
    <div class="grid grid-cols-1 gap-4 md:grid-cols-6">
        @if ($appointment->exists)
            <div class="md:col-span-6">
                <p class="label">Paciente</p>
                <p class="text-sm text-slate-700">{{ $patient->name }}</p>
            </div>
        @else
            <x-patient-picker class="md:col-span-6" :patient="$patient" />
        @endif

        <x-form.input class="md:col-span-3" name="scheduled_at" label="Data e hora" type="datetime-local" required step="300"
                      :value="ClinicTime::toInput($appointment->scheduled_at)" data-ends-at-source />
        <x-form.select class="md:col-span-3" name="duration_minutes" label="Duração" :options="Duration::options()" :selected="$appointment->duration_minutes" required
                       data-ends-at-duration hint="Termina às —" />

        <x-form.select class="md:col-span-3" name="professional_id" label="Profissional" required placeholder="Selecione"
                       :options="$professionals->pluck('name', 'id')->all()" :selected="$appointment->professional_id" />

        {{-- Escolher o procedimento ajusta a duração para a estimada no catálogo (ver app.js). --}}
        <div class="md:col-span-3">
            <label for="procedure_id" class="label">Procedimento previsto</label>
            <select id="procedure_id" name="procedure_id" data-duration-source="duration_minutes"
                    @error('procedure_id') aria-invalid="true" aria-describedby="procedure_id-error" @enderror
                    @class(['input', 'input-error' => $errors->has('procedure_id')])>
                <option value="">A definir</option>
                @foreach ($procedures as $procedure)
                    <option value="{{ $procedure->id }}" data-duration="{{ $procedure->duration_minutes }}"
                            @selected((string) old('procedure_id', $appointment->procedure_id) === (string) $procedure->id)>{{ $procedure->name }}</option>
                @endforeach
            </select>
            @error('procedure_id')
                <p id="procedure_id-error" class="mt-1 text-sm text-red-700">{{ $message }}</p>
            @enderror
        </div>

        {{-- Horários já ocupados do profissional no dia escolhido, consultados pelo app.js. --}}
        <div class="md:col-span-6" data-occupancy data-url="{{ route('agenda.occupancy') }}" @if ($appointment->exists) data-except="{{ $appointment->id }}" @endif hidden>
            <p class="label">Horários ocupados no dia</p>
            <p class="text-sm text-slate-600" data-occupancy-status></p>
            <ul class="mt-1 flex flex-wrap gap-1.5 text-xs" data-occupancy-list></ul>
        </div>

        <x-form.textarea class="md:col-span-6" name="notes" label="Observações da agenda" :value="$appointment->notes" rows="2" maxlength="500"
                         hint="Recados de agendamento, como encaixe ou preferência de horário. Informações clínicas vão no prontuário." />
    </div>
</section>

<div class="flex flex-wrap items-center justify-end gap-3">
    <a href="{{ $cancelUrl }}" class="btn btn-secondary">Cancelar</a>
    <button type="submit" class="btn btn-primary">{{ $appointment->exists ? 'Salvar alterações' : 'Agendar' }}</button>
</div>
