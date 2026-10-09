@php
    use App\Enums\AppointmentStatus;
    use App\Support\ClinicTime;

    $iso = $date->format('Y-m-d');
    $pt = fn ($day, string $format) => $day->locale('pt_BR')->translatedFormat($format);
    $link = fn (string $day) => route('agenda.index', ['data' => $day]);
    // Escolher um dia recarrega a agenda com a lista dele no painel. A âncora
    // leva até o painel no celular, onde ele fica abaixo do calendário.
    $dayLink = fn (string $day) => $link($day).'#dia';
    $count = fn (int $total) => $total.' '.($total === 1 ? 'agendamento' : 'agendamentos');
    // No calendário, cancelados aparecem riscados.
    $struck = fn ($appointment) => $appointment->status === AppointmentStatus::Cancelled ? 'text-slate-400 line-through' : '';
@endphp
<x-layouts.app title="Agenda" wide>
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold tracking-tight">Agenda</h1>
            <p class="mt-1 text-sm text-slate-600">{{ ucfirst($pt($date, 'F \d\e Y')) }}</p>
        </div>
        @can('create', App\Models\Appointment::class)
            <a href="{{ route('appointments.create', ['data' => $iso]) }}" class="btn btn-primary">Novo agendamento</a>
        @endcan
    </div>

    <div class="mt-6 flex flex-wrap items-center gap-2">
        <a href="{{ $link($previous) }}" class="btn btn-secondary">← Mês anterior</a>
        <a href="{{ $link($today) }}" class="btn btn-secondary">Hoje</a>
        <a href="{{ $link($next) }}" class="btn btn-secondary">Próximo mês →</a>

        <form method="GET" action="{{ route('agenda.index') }}" class="ml-auto flex items-start gap-2">
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

    <div class="mt-6 grid grid-cols-1 items-start gap-5 lg:grid-cols-[minmax(0,1fr)_18rem]">
        <div>
            {{-- O que cabe em cada dia depende da largura do calendário, não da tela:
                 estreito (celular, ou ao lado do painel) mostra só a quantidade. --}}
            <div class="card @container overflow-hidden">
                <table class="w-full table-fixed border-collapse text-sm">
                    <thead class="border-b border-slate-200 bg-slate-50 text-xs tracking-wide text-slate-500 uppercase">
                        <tr>
                            @foreach (array_slice($days, 0, 7) as $day)
                                <th scope="col" class="px-0.5 py-2 font-medium @xl:px-2">{{ $pt($day, 'D') }}</th>
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
                                        $selected = $key === $iso;
                                    @endphp
                                    <td @class([
                                        'relative h-16 p-1 text-center align-top @xl:h-28 @xl:p-1.5 @xl:text-left @4xl:h-32 @4xl:p-2',
                                        'bg-slate-50/70 text-slate-400' => $day->month !== $date->month,
                                        'hover:bg-slate-100' => ! $selected,
                                        'bg-teal-50 ring-2 ring-teal-600 ring-inset' => $selected,
                                    ])>
                                        {{-- O link do número cobre a célula inteira: tocar em qualquer ponto escolhe o dia. --}}
                                        <a href="{{ $dayLink($key) }}" title="{{ $pt($day, 'l, j \d\e F') }}" @if ($selected) aria-current="true" @endif
                                           @class(['inline-flex size-7 items-center justify-center rounded-full text-sm tabular-nums after:absolute after:inset-0', 'bg-teal-700 font-semibold text-white' => $key === $today])>{{ $day->day }}</a>
                                        @if ($items->isNotEmpty())
                                            <p class="mx-auto mt-1 flex size-6 items-center justify-center rounded-full bg-teal-100 text-xs font-semibold text-teal-800 tabular-nums @xl:hidden">
                                                {{ $items->count() }}<span class="sr-only"> {{ $items->count() === 1 ? 'agendamento' : 'agendamentos' }}</span>
                                            </p>
                                            <ul class="mt-1 hidden space-y-0.5 @xl:block">
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
                                                <p class="mt-0.5 hidden text-xs font-medium text-teal-800 @xl:block">+{{ $items->count() - 3 }} no dia</p>
                                            @endif
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <ul class="mt-4 flex flex-wrap gap-x-4 gap-y-1 text-xs text-slate-600" aria-label="Legenda">
                @foreach (AppointmentStatus::cases() as $status)
                    <li class="flex items-center gap-1.5"><span class="size-2 rounded-full {{ $status->dotClasses() }}"></span>{{ $status->label() }}</li>
                @endforeach
            </ul>
        </div>

        {{-- Em telas largas o painel acompanha a rolagem e rola por dentro quando o dia é cheio.
             A margem de rolagem anula ali o salto da âncora, que só serve ao celular. --}}
        <section id="dia" class="card lg:sticky lg:top-4 lg:max-h-[calc(100vh-2rem)] lg:scroll-mt-[100vh] lg:overflow-y-auto" aria-labelledby="dia-titulo">
            <div class="px-4 pt-4 pb-2 sm:px-6 lg:px-4">
                <h2 id="dia-titulo" class="text-sm font-semibold">{{ ucfirst($pt($date, 'l, j \d\e F \d\e Y')) }}{{ $iso === $today ? ', hoje' : '' }}</h2>
                @if ($dayAppointments->isNotEmpty())
                    <p class="mt-0.5 text-xs text-slate-500">{{ $count($dayAppointments->count()) }}</p>
                @endif
            </div>
            @if ($dayAppointments->isEmpty())
                <p class="px-4 py-10 text-center text-sm text-slate-500 sm:px-6">Nenhum agendamento para este dia.</p>
            @else
                @include('appointments._list', ['appointments' => $dayAppointments, 'dense' => true])
            @endif
        </section>
    </div>
</x-layouts.app>
