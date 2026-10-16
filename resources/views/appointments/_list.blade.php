@php
    use App\Enums\AppointmentStatus;
    use App\Support\ClinicTime;

    // Lista compacta: uma linha por agendamento quando a tela comporta. No
    // celular os botões ficam mais altos, para o toque.
    $small = 'px-2.5 py-2 text-xs sm:py-1';

    // "dense" é a lista no painel lateral da agenda: em telas largas, onde o
    // painel é estreito, letra e respiros diminuem.
    $dense ??= false;
    $small .= $dense ? ' lg:px-2 lg:py-0.5 lg:text-[11px]' : '';
    $text = $dense ? 'text-sm lg:text-[13px]' : 'text-sm';
    $statusButton = fn (AppointmentStatus $status) => $status->value;
@endphp
{{-- Lista de agendamentos de um dia, com as ações de cada um. Espera $appointments
     com patient, professional, procedure e treatment já carregados. --}}
<ul class="divide-y divide-slate-100">
    @foreach ($appointments as $appointment)
        @php $patient = $appointment->patient; @endphp
        <li @class(['flex flex-wrap items-center gap-x-4 gap-y-2 px-4 py-2.5 sm:px-6', 'lg:gap-y-1.5 lg:px-4 lg:py-2' => $dense])>
            <p class="w-28 shrink-0 tabular-nums">
                <span class="{{ $text }} font-semibold">{{ ClinicTime::format($appointment->scheduled_at, 'H:i') }}</span>
                <span class="text-xs text-slate-500">até {{ ClinicTime::format($appointment->ends_at, 'H:i') }}</span>
            </p>

            <div class="min-w-0 flex-1 basis-56 {{ $text }}">
                <p class="flex flex-wrap items-center gap-x-2 gap-y-0.5">
                    <span class="font-medium">
                        @can('view', $patient)
                            <a href="{{ route('patients.show', $patient) }}" class="text-teal-800 hover:underline">{{ $patient->name }}</a>
                        @else
                            {{ $patient->name }}
                        @endcan
                    </span>
                    <span @class(['badge', $appointment->status->badgeClasses(), 'lg:px-2 lg:text-[11px]' => $dense])>{{ $appointment->status->label() }}</span>
                    <span @class(['text-slate-600', 'lg:text-xs' => $dense])>{{ $appointment->procedure?->name ?? 'Procedimento a definir' }} · {{ $appointment->professional->name }}</span>
                </p>
                @if ($appointment->notes)
                    <p class="mt-0.5 text-xs break-words text-slate-500">{{ $appointment->notes }}</p>
                @endif
            </div>

            <div class="flex flex-wrap items-center gap-1.5">
                @if ($appointment->isOpen())
                    {{-- Agendamento de dia futuro ainda não recebe o registro do atendimento. --}}
                    @can('recordTreatment', $appointment)
                        <a href="{{ route('patients.treatments.create', [$patient, 'agendamento' => $appointment->id]) }}" class="btn btn-primary {{ $small }}">Registrar atendimento</a>
                    @endcan

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
