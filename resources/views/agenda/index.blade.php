@php
    use App\Enums\AppointmentStatus;
    use App\Support\ClinicTime;

    $iso = $date->format('Y-m-d');
    $pt = fn ($day, string $format) => $day->locale('pt_BR')->translatedFormat($format);
    $link = fn (string $day, string $mode) => route('agenda.index', ['visao' => $mode, 'data' => $day]);
    $firstDay = $days[0];
    $lastDay = $days[array_key_last($days)];

    $heading = match ($view) {
        'semana' => $pt($firstDay, 'j \d\e F').' a '.$pt($lastDay, 'j \d\e F \d\e Y'),
        'mes' => ucfirst($pt($date, 'F \d\e Y')),
        default => ucfirst($pt($date, 'l, j \d\e F \d\e Y')).($iso === $today ? ', hoje' : ''),
    };
    $tab = fn (bool $active) => 'rounded-lg px-3 py-1.5 text-sm font-medium '.($active ? 'bg-teal-700 text-white' : 'text-slate-700 hover:bg-slate-100');
    $labels = ['dia' => ['Dia anterior', 'Próximo dia'], 'semana' => ['Semana anterior', 'Próxima semana'], 'mes' => ['Mês anterior', 'Próximo mês']][$view];
    // Nas visões resumidas, cancelados aparecem riscados.
    $struck = fn ($appointment) => $appointment->status === AppointmentStatus::Cancelled ? 'text-slate-400 line-through' : '';
@endphp
<x-layouts.app title="Agenda">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold tracking-tight">Agenda</h1>
            <p class="mt-1 text-sm text-slate-600">{{ $heading }}</p>
        </div>
        @can('create', App\Models\Appointment::class)
            <a href="{{ route('appointments.create', ['data' => $iso]) }}" class="btn btn-primary">Novo agendamento</a>
        @endcan
    </div>

    <div class="mt-6 flex flex-wrap items-center gap-2">
        <nav class="flex items-center gap-1 rounded-xl border border-slate-200 bg-white p-1" aria-label="Visão da agenda">
            @foreach ($views as $mode => $label)
                <a href="{{ $link($iso, $mode) }}" class="{{ $tab($view === $mode) }}" @if ($view === $mode) aria-current="page" @endif>{{ $label }}</a>
            @endforeach
        </nav>

        <a href="{{ $link($previous, $view) }}" class="btn btn-secondary">← {{ $labels[0] }}</a>
        <a href="{{ $link($today, $view) }}" class="btn btn-secondary">Hoje</a>
        <a href="{{ $link($next, $view) }}" class="btn btn-secondary">{{ $labels[1] }} →</a>

        <form method="GET" action="{{ route('agenda.index') }}" class="ml-auto flex items-start gap-2">
            <input type="hidden" name="visao" value="{{ $view }}">
            <div>
                <label for="data" class="sr-only">Ir para a data</label>
                <input id="data" name="data" type="date" value="{{ $iso }}" required @class(['input', 'input-error' => $errors->has('data')])>
                @error('data')
                    <p class="mt-1 text-sm text-red-700">{{ $message }}</p>
                @enderror
            </div>
            <button type="submit" class="btn btn-secondary">Ir</button>
        </form>
    </div>
    @error('visao')
        <p class="mt-2 text-sm text-red-700">{{ $message }}</p>
    @enderror

    @if ($view === 'dia')
        @php $appointments = $appointmentsByDay->get($iso, collect()); @endphp
        <section class="card mt-6" aria-labelledby="agenda">
            <h2 id="agenda" class="px-4 pt-5 pb-3 text-sm font-semibold tracking-wide text-slate-500 uppercase sm:px-6">
                {{ $appointments->count() }} {{ $appointments->count() === 1 ? 'agendamento' : 'agendamentos' }}
            </h2>
            @if ($appointments->isEmpty())
                <p class="px-4 py-10 text-center text-sm text-slate-500 sm:px-6">Nenhum agendamento para este dia.</p>
            @else
                @include('appointments._list', ['appointments' => $appointments])
            @endif
        </section>
    @elseif ($view === 'semana')
        <div class="mt-6 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-7">
            @foreach ($days as $day)
                @php $key = $day->format('Y-m-d'); $items = $appointmentsByDay->get($key, collect()); @endphp
                <section @class(['card flex flex-col p-3', 'ring-2 ring-teal-600' => $key === $today]) aria-label="{{ $pt($day, 'l, j \d\e F') }}">
                    <a href="{{ $link($key, 'dia') }}" class="flex items-baseline justify-between gap-2 rounded-md px-1 hover:bg-slate-50">
                        <span class="text-xs font-semibold tracking-wide text-slate-500 uppercase">{{ $pt($day, 'D') }}</span>
                        <span class="text-lg font-semibold tabular-nums">{{ $day->format('d') }}</span>
                    </a>
                    @if ($items->isEmpty())
                        <p class="mt-3 px-1 text-xs text-slate-400">Livre</p>
                    @else
                        <ul class="mt-2 space-y-2">
                            @foreach ($items as $appointment)
                                <li class="flex gap-2 px-1 text-xs">
                                    <span class="mt-1 size-2 shrink-0 rounded-full {{ $appointment->status->dotClasses() }}" title="{{ $appointment->status->label() }}"></span>
                                    <span class="min-w-0 {{ $struck($appointment) }}">
                                        <span class="font-semibold tabular-nums">{{ ClinicTime::format($appointment->scheduled_at, 'H:i') }}</span>
                                        <span class="block truncate">{{ $appointment->patient->name }}</span>
                                        <span class="block truncate text-slate-500">{{ $appointment->procedure?->name ?? 'A definir' }}</span>
                                        <span class="sr-only">{{ $appointment->status->label() }}</span>
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>
            @endforeach
        </div>
    @else
        <div class="card mt-6 overflow-x-auto">
            <table class="w-full min-w-3xl table-fixed border-collapse text-sm">
                <thead class="border-b border-slate-200 bg-slate-50 text-xs tracking-wide text-slate-500 uppercase">
                    <tr>
                        @foreach (array_slice($days, 0, 7) as $day)
                            <th scope="col" class="px-2 py-2 font-medium">{{ $pt($day, 'D') }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach (array_chunk($days, 7) as $week)
                        <tr class="divide-x divide-slate-100 border-b border-slate-100 last:border-b-0">
                            @foreach ($week as $day)
                                @php
                                    $key = $day->format('Y-m-d');
                                    $items = $appointmentsByDay->get($key, collect());
                                    $inMonth = $day->month === $date->month;
                                @endphp
                                <td @class(['h-28 p-1.5 align-top', 'bg-slate-50/70 text-slate-400' => ! $inMonth])>
                                    <a href="{{ $link($key, 'dia') }}" title="{{ $pt($day, 'l, j \d\e F') }}"
                                       @class(['inline-flex size-7 items-center justify-center rounded-full text-sm tabular-nums hover:bg-slate-200', 'bg-teal-700 font-semibold text-white hover:bg-teal-800' => $key === $today])>{{ $day->day }}</a>
                                    @if ($items->isNotEmpty())
                                        <ul class="mt-1 space-y-0.5">
                                            @foreach ($items->take(3) as $appointment)
                                                <li class="flex items-center gap-1 text-xs">
                                                    <span class="size-1.5 shrink-0 rounded-full {{ $appointment->status->dotClasses() }}"></span>
                                                    <span class="truncate {{ $struck($appointment) }}">
                                                        <span class="tabular-nums">{{ ClinicTime::format($appointment->scheduled_at, 'H:i') }}</span> {{ $appointment->patient->name }}
                                                    </span>
                                                </li>
                                            @endforeach
                                        </ul>
                                        @if ($items->count() > 3)
                                            <a href="{{ $link($key, 'dia') }}" class="mt-0.5 block text-xs font-medium text-teal-800 hover:underline">+{{ $items->count() - 3 }} no dia</a>
                                        @endif
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @if ($view !== 'dia')
        <ul class="mt-4 flex flex-wrap gap-x-4 gap-y-1 text-xs text-slate-600" aria-label="Legenda">
            @foreach (AppointmentStatus::cases() as $status)
                <li class="flex items-center gap-1.5"><span class="size-2 rounded-full {{ $status->dotClasses() }}"></span>{{ $status->label() }}</li>
            @endforeach
        </ul>
    @endif
</x-layouts.app>
