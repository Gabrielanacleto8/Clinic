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
    // Visão, filtro e dia da agenda que a ação deve manter ao voltar (ver AppointmentController::backToAgenda).
    $agendaQuery ??= [];
    // Lista que mistura dias (pendentes de registro) mostra a data junto da hora.
    $showDate ??= false;
@endphp
{{-- Lista de agendamentos de um dia, com as ações de cada um. Espera $appointments
     com patient (id, name, phone), professional (id, name, is_active), procedure e treatment já carregados. --}}
<ul class="divide-y divide-slate-100">
    @foreach ($appointments as $appointment)
        @php $patient = $appointment->patient; @endphp
        <li id="ag-{{ $appointment->id }}" @class(['scroll-mt-20 flex flex-wrap items-center gap-x-4 gap-y-2 px-4 py-2.5 sm:px-6', 'lg:gap-y-1.5 lg:px-4 lg:py-2' => $dense])>
            <p class="w-28 shrink-0 tabular-nums">
                @if ($showDate)
                    <span class="block text-xs text-slate-500">{{ ClinicTime::format($appointment->scheduled_at, 'd/m/Y') }}</span>
                @endif
                <span class="{{ $text }} font-semibold">{{ ClinicTime::format($appointment->scheduled_at, 'H:i') }}</span>
                <span class="text-xs text-slate-500">até {{ ClinicTime::format($appointment->ends_at, 'H:i') }}</span>
            </p>

            <div class="min-w-0 flex-1 basis-56 {{ $text }}">
                <p class="flex flex-wrap items-center gap-x-2 gap-y-0.5">
                    <span class="font-medium">
                        @can('view', $patient)
                            <a href="{{ route('patients.show', $patient) }}" class="text-slate-800 hover:underline">{{ $patient->name }}</a>
                        @else
                            {{ $patient->name }}
                        @endcan
                    </span>
                    <span @class(['badge', $appointment->status->badgeClasses(), 'lg:px-2 lg:text-[11px]' => $dense])>{{ $appointment->status->label() }}</span>
                    <span @class(['text-slate-600', 'lg:text-xs' => $dense])>{{ $appointment->procedure?->name ?? 'Procedimento a definir' }} · {{ $appointment->professional->name }}</span>
                </p>
                @if ($patient->phone)
                    @can('view', $patient)
                        {{-- Para confirmar ou avisar o paciente sem abrir a ficha. --}}
                        <p class="mt-0.5 flex flex-wrap gap-x-3 text-xs">
                            <a href="tel:+55{{ $patient->phone }}" class="text-slate-800 tabular-nums hover:underline">{{ $patient->formattedPhone() }}</a>
                            <a href="https://wa.me/55{{ $patient->phone }}" target="_blank" rel="noopener noreferrer" class="text-slate-800 hover:underline">WhatsApp</a>
                        </p>
                    @endcan
                @endif
                @if ($appointment->notes)
                    <p class="mt-0.5 text-xs break-words text-slate-500">{{ $appointment->notes }}</p>
                @endif
            </div>

            <div class="flex flex-wrap items-center gap-1.5">
                @if ($appointment->isOpen())
                    {{-- Agendamento de dia futuro ainda não recebe o registro do atendimento. --}}
                    @can('recordTreatment', $appointment)
                        <a href="{{ route('patients.treatments.create', [$patient, 'agendamento' => $appointment->id, 'origem' => ($origin ?? App\Enums\TreatmentOrigin::Agenda)->value]) }}" class="btn btn-primary {{ $small }}">Registrar atendimento</a>
                    @endcan

                    @can('changeStatus', $appointment)
                        {{-- Confirmar presença, ou desfazer a confirmação dada por engano. --}}
                        @php $confirmed = $appointment->status === AppointmentStatus::Confirmed; @endphp
                        <form method="POST" action="{{ route('appointments.status', $appointment) }}">
                            @csrf
                            @method('PATCH')
                            @foreach ($agendaQuery as $key => $value)
                                <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                            @endforeach
                            <input type="hidden" name="status" value="{{ $statusButton($confirmed ? AppointmentStatus::Scheduled : AppointmentStatus::Confirmed) }}">
                            <button type="submit" class="btn btn-secondary {{ $small }}">{{ $confirmed ? 'Desfazer confirmação' : 'Confirmar presença' }}</button>
                        </form>
                    @endcan
                    {{-- Ações menos frequentes ficam em "Mais", que se abre na própria linha:
                         a lista do Início e da agenda fica curta no celular. --}}
                    @if (auth()->user()->can('update', $appointment) || auth()->user()->can('changeStatus', $appointment))
                    <details class="group/more">
                        <summary class="btn btn-secondary {{ $small }} list-none">Mais <x-icon name="chevron-down" class="size-3.5 transition-transform group-open/more:rotate-180" /></summary>
                        <div class="mt-1.5 flex flex-wrap items-center gap-1.5">
                    @can('update', $appointment)
                        <a href="{{ route('appointments.edit', $appointment) }}" class="btn btn-secondary {{ $small }}">Remarcar</a>
                    @endcan
                    @can('changeStatus', $appointment)
                        @if ($appointment->canBeMarkedMissed())
                            <form method="POST" action="{{ route('appointments.status', $appointment) }}" data-confirm="Marcar falta de {{ $patient->name }}?">
                                @csrf
                                @method('PATCH')
                                @foreach ($agendaQuery as $key => $value)
                                    <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                                @endforeach
                                <input type="hidden" name="status" value="{{ $statusButton(AppointmentStatus::NoShow) }}">
                                <button type="submit" class="btn btn-secondary {{ $small }}">Faltou</button>
                            </form>
                        @endif
                        <form method="POST" action="{{ route('appointments.status', $appointment) }}" data-confirm="Cancelar o agendamento de {{ $patient->name }}?">
                            @csrf
                            @method('PATCH')
                            @foreach ($agendaQuery as $key => $value)
                                <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                            @endforeach
                            <input type="hidden" name="status" value="{{ $statusButton(AppointmentStatus::Cancelled) }}">
                            <button type="submit" class="btn btn-danger {{ $small }}">Cancelar</button>
                        </form>
                    @endcan
                        </div>
                    </details>
                    @endif
                @elseif ($appointment->status === AppointmentStatus::Completed)
                    @if ($appointment->treatment)
                        @can('view', $appointment->treatment)
                            <a href="{{ route('patients.treatments.show', [$patient, $appointment->treatment]) }}" class="btn btn-secondary {{ $small }}">Ver atendimento</a>
                        @endcan
                    @endif
                @elseif ($appointment->canBeReopened())
                    @can('changeStatus', $appointment)
                        <form method="POST" action="{{ route('appointments.status', $appointment) }}">
                            @csrf
                            @method('PATCH')
                            @foreach ($agendaQuery as $key => $value)
                                <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                            @endforeach
                            <input type="hidden" name="status" value="{{ $statusButton(AppointmentStatus::Scheduled) }}">
                            <button type="submit" class="btn btn-secondary {{ $small }}">Reabrir</button>
                        </form>
                    @endcan
                @endif
            </div>
        </li>
    @endforeach
</ul>

