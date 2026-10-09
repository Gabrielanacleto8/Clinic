@php
    use App\Enums\AppointmentStatus;
    use App\Enums\TreatmentStatus;
    use App\Support\ClinicTime;

    // No celular os botões ficam mais altos, para o toque.
    $small = 'px-3 py-2 text-xs sm:py-1.5';
    $heading = 'flex items-center gap-2 px-4 pt-5 pb-3 text-sm font-semibold tracking-wide text-slate-500 uppercase sm:px-6';
    $row = 'flex flex-wrap items-center gap-x-6 gap-y-3 px-4 py-4 sm:px-6';
@endphp
<x-layouts.app title="Atendimentos">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold tracking-tight">Atendimentos</h1>
            <p class="mt-1 text-sm text-slate-600">Quem está em atendimento e quem espera a vez.</p>
        </div>
        <a href="{{ route('agenda.index') }}" class="btn btn-secondary">Abrir a agenda</a>
    </div>

    <section class="card mt-6" aria-labelledby="em-atendimento">
        <h2 id="em-atendimento" class="{{ $heading }}">
            Em atendimento
            <span class="badge {{ TreatmentStatus::Draft->badgeClasses() }}">{{ $inProgress->count() }}</span>
        </h2>
        @if ($inProgress->isEmpty())
            <p class="px-4 py-10 text-center text-sm text-slate-500 sm:px-6">Nenhum paciente em atendimento.</p>
        @else
            <ul class="divide-y divide-slate-100">
                @foreach ($inProgress as $treatment)
                    @php
                        $patient = $treatment->patient;
                        $startedToday = ClinicTime::format($treatment->performed_at, 'Y-m-d') === $today;
                    @endphp
                    <li class="{{ $row }}">
                        <div class="w-20 shrink-0 tabular-nums">
                            <p class="text-base font-semibold">{{ ClinicTime::format($treatment->performed_at, 'H:i') }}</p>
                            {{-- Atendimento de outro dia que ficou sem finalizar. --}}
                            <p @class(['text-xs', 'text-slate-500' => $startedToday, 'font-medium text-amber-700' => ! $startedToday])>
                                {{ $startedToday ? 'início' : 'desde '.ClinicTime::format($treatment->performed_at, 'd/m') }}
                            </p>
                        </div>

                        <div class="min-w-0 flex-1 basis-56">
                            <p class="font-medium">
                                @can('view', $patient)
                                    <a href="{{ route('patients.show', $patient) }}" class="text-teal-800 hover:underline">{{ $patient->name }}</a>
                                @else
                                    {{ $patient->name }}
                                @endcan
                            </p>
                            <p class="mt-0.5 text-sm text-slate-600">
                                {{-- O procedimento do atendimento é dado do prontuário. --}}
                                @can('view', $treatment)
                                    {{ $treatment->procedure->name }} ·
                                @endcan
                                {{ $treatment->professional->name }}
                            </p>
                        </div>

                        @can('view', $treatment)
                            <a href="{{ route('patients.treatments.show', [$patient, $treatment]) }}" class="btn btn-primary {{ $small }}">
                                {{ auth()->user()->can('update', $treatment) ? 'Continuar atendimento' : 'Ver atendimento' }}
                            </a>
                        @endcan
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    <section class="card mt-6" aria-labelledby="em-espera">
        <h2 id="em-espera" class="{{ $heading }}">
            Em espera
            <span class="badge {{ AppointmentStatus::Confirmed->badgeClasses() }}">{{ $waiting->count() }}</span>
        </h2>
        @if ($waiting->isEmpty())
            <p class="px-4 py-10 text-center text-sm text-slate-500 sm:px-6">
                Nenhum paciente em espera. O paciente entra na espera quando a presença é confirmada na agenda.
            </p>
        @else
            <ul class="divide-y divide-slate-100">
                @foreach ($waiting as $appointment)
                    @php $patient = $appointment->patient; @endphp
                    <li class="{{ $row }}">
                        <div class="w-20 shrink-0 tabular-nums">
                            <p class="text-base font-semibold">{{ ClinicTime::format($appointment->scheduled_at, 'H:i') }}</p>
                            <p class="text-xs text-slate-500">agendado</p>
                        </div>

                        <div class="min-w-0 flex-1 basis-56">
                            <p class="font-medium">
                                @can('view', $patient)
                                    <a href="{{ route('patients.show', $patient) }}" class="text-teal-800 hover:underline">{{ $patient->name }}</a>
                                @else
                                    {{ $patient->name }}
                                @endcan
                            </p>
                            <p class="mt-0.5 text-sm text-slate-600">
                                {{ $appointment->procedure?->name ?? 'Procedimento a definir' }} · {{ $appointment->professional->name }}
                            </p>
                        </div>

                        @can('startTreatment', $appointment)
                            <a href="{{ route('patients.treatments.create', [$patient, 'agendamento' => $appointment->id]) }}" class="btn btn-primary {{ $small }}">Iniciar atendimento</a>
                        @endcan
                    </li>
                @endforeach
            </ul>
        @endif

        @if ($expectedCount > 0)
            <p class="border-t border-slate-100 px-4 py-3 text-sm text-slate-600 sm:px-6">
                {{ $expectedCount === 1 ? 'Mais 1 paciente agendado para hoje ainda sem presença confirmada.' : "Mais {$expectedCount} pacientes agendados para hoje ainda sem presença confirmada." }}
                <a href="{{ route('agenda.index') }}" class="text-teal-800 hover:underline">Confirmar na agenda</a>
            </p>
        @endif
    </section>
</x-layouts.app>
