@php
    use App\Enums\AppointmentStatus;
    use App\Support\ClinicTime;

    $small = 'px-3 py-1.5 text-xs';
    $statusButton = fn (AppointmentStatus $status) => $status->value;
@endphp
{{-- Lista de agendamentos de um dia, com as ações de cada um. Espera $appointments
     com patient, professional, procedure e treatment já carregados. --}}
<ul class="divide-y divide-slate-100">
    @foreach ($appointments as $appointment)
        @php $patient = $appointment->patient; @endphp
        <li class="flex flex-wrap items-center gap-x-6 gap-y-3 px-4 py-4 sm:px-6">
            <div class="w-20 shrink-0 tabular-nums">
                <p class="text-base font-semibold">{{ ClinicTime::format($appointment->scheduled_at, 'H:i') }}</p>
                <p class="text-xs text-slate-500">até {{ ClinicTime::format($appointment->ends_at, 'H:i') }}</p>
            </div>

            <div class="min-w-0 flex-1 basis-56">
                <p class="font-medium">
                    @can('view', $patient)
                        <a href="{{ route('patients.show', $patient) }}" class="text-teal-800 hover:underline">{{ $patient->name }}</a>
                    @else
                        {{ $patient->name }}
                    @endcan
                    <span class="badge ml-1 align-middle {{ $appointment->status->badgeClasses() }}">{{ $appointment->status->label() }}</span>
                </p>
                <p class="mt-0.5 text-sm text-slate-600">
                    {{ $appointment->procedure?->name ?? 'Procedimento a definir' }} · {{ $appointment->professional->name }}
                </p>
                @if ($appointment->notes)
                    <p class="mt-0.5 text-xs break-words text-slate-500">{{ $appointment->notes }}</p>
                @endif
            </div>

            <div class="flex flex-wrap items-center gap-2">
                @if ($appointment->isOpen())
                    @if ($appointment->treatment)
                        @can('view', $appointment->treatment)
                            <a href="{{ route('patients.treatments.show', [$patient, $appointment->treatment]) }}" class="btn btn-primary {{ $small }}">Continuar atendimento</a>
                        @endcan
                    @else
                        @can('startTreatment', $appointment)
                            {{-- Agendamento de dia futuro ainda não pode virar atendimento. --}}
                            @if (ClinicTime::format($appointment->scheduled_at, 'Y-m-d') <= ClinicTime::today())
                                <a href="{{ route('patients.treatments.create', [$patient, 'agendamento' => $appointment->id]) }}" class="btn btn-primary {{ $small }}">Iniciar atendimento</a>
                            @endif
                        @endcan
                    @endif

                    @can('changeStatus', $appointment)
                        @if ($appointment->status === AppointmentStatus::Scheduled)
                            <form method="POST" action="{{ route('appointments.status', $appointment) }}">
                                @csrf
                                @method('PATCH')
                                <input type="hidden" name="status" value="{{ $statusButton(AppointmentStatus::Confirmed) }}">
                                <button type="submit" class="btn btn-secondary {{ $small }}">Confirmar presença</button>
                            </form>
                        @endif
                    @endcan
                    @can('update', $appointment)
                        <a href="{{ route('appointments.edit', $appointment) }}" class="btn btn-secondary {{ $small }}">Remarcar</a>
                    @endcan
                    @can('changeStatus', $appointment)
                        <form method="POST" action="{{ route('appointments.status', $appointment) }}">
                            @csrf
                            @method('PATCH')
                            <input type="hidden" name="status" value="{{ $statusButton(AppointmentStatus::NoShow) }}">
                            <button type="submit" class="btn btn-secondary {{ $small }}">Faltou</button>
                        </form>
                        <form method="POST" action="{{ route('appointments.status', $appointment) }}" data-confirm="Cancelar o agendamento de {{ $patient->name }}?">
                            @csrf
                            @method('PATCH')
                            <input type="hidden" name="status" value="{{ $statusButton(AppointmentStatus::Cancelled) }}">
                            <button type="submit" class="btn btn-danger {{ $small }}">Cancelar</button>
                        </form>
                    @endcan
                @elseif ($appointment->status === AppointmentStatus::Completed)
                    @if ($appointment->treatment)
                        @can('view', $appointment->treatment)
                            <a href="{{ route('patients.treatments.show', [$patient, $appointment->treatment]) }}" class="btn btn-secondary {{ $small }}">Ver atendimento</a>
                        @endcan
                    @endif
                @else
                    @can('changeStatus', $appointment)
                        <form method="POST" action="{{ route('appointments.status', $appointment) }}">
                            @csrf
                            @method('PATCH')
                            <input type="hidden" name="status" value="{{ $statusButton(AppointmentStatus::Scheduled) }}">
                            <button type="submit" class="btn btn-secondary {{ $small }}">Reabrir</button>
                        </form>
                    @endcan
                @endif
            </div>
        </li>
    @endforeach
</ul>

@foreach (['status', 'scheduled_at'] as $field)
    @error($field)
        <p class="border-t border-slate-100 px-4 py-3 text-sm text-red-700 sm:px-6" role="alert">{{ $message }}</p>
    @enderror
@endforeach
