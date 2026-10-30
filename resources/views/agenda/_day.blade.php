@php
    use App\Support\ClinicTime;

    $slots = $dayGrid->slots();
    $columns = $dayGrid->columns();
    // Atalho de novo agendamento só para horários que ainda não passaram.
    $now = ClinicTime::local(now())->format('Y-m-d H:i');
@endphp
{{-- Visão do dia: uma coluna por profissional, uma linha a cada 15 minutos (ver AgendaDayGrid).
     No celular as colunas rolam de lado, com os horários fixos à esquerda. --}}
<div class="card relative overflow-x-auto">
    @if ($columns === [])
        <p class="px-4 py-10 text-center text-sm text-slate-500">Nenhum profissional atende neste dia.</p>
    @else
        <div class="flex min-w-max">
            <div class="sticky left-0 z-20 w-14 shrink-0 border-r border-slate-200 bg-white" aria-hidden="true">
                <div class="h-10 border-b border-slate-200"></div>
                <div class="grid auto-rows-[1.25rem]">
                    <div class="col-start-1 row-start-1 row-span-{{ $slots }}"></div>
                    @foreach ($dayGrid->hours() as $hour)
                        <div class="col-start-1 row-start-{{ $hour['row'] }} pr-2 text-right text-[11px] leading-none text-slate-500 tabular-nums">{{ $hour['label'] }}</div>
                    @endforeach
                </div>
            </div>

            @foreach ($columns as $column)
                <section class="w-44 shrink-0 border-r border-slate-100 last:border-r-0 sm:w-auto sm:min-w-44 sm:flex-1" aria-label="{{ $column['name'] }}">
                    <h3 class="flex h-10 items-center truncate border-b border-slate-200 px-2 text-sm font-medium">{{ $column['name'] }}</h3>
                    <div class="grid auto-rows-[1.25rem]">
                        <div class="col-start-1 row-start-1 row-span-{{ $slots }}"></div>
                        @foreach ($dayGrid->hours() as $hour)
                            <div class="col-start-1 row-start-{{ $hour['row'] }} row-span-4 border-t border-slate-100" aria-hidden="true"></div>
                        @endforeach

                        {{-- Clicar num horário livre abre o agendamento já com dia, hora e profissional.
                             É só atalho do mouse: "Novo agendamento" faz o mesmo pelo teclado. --}}
                        @if ($canSchedule)
                            @foreach ($dayGrid->halfHours() as $slot)
                                @if ($iso.' '.$slot['time'] > $now)
                                    <a href="{{ route('appointments.create', ['data' => $iso, 'hora' => $slot['time'], 'profissional' => $column['id']]) }}"
                                       class="group col-start-1 row-start-{{ $slot['row'] }} row-span-2 flex items-center justify-center hover:bg-slate-50"
                                       tabindex="-1" aria-hidden="true" title="Agendar às {{ $slot['time'] }} com {{ $column['name'] }}">
                                        <span class="hidden text-xs font-medium text-slate-800 group-hover:inline">+ {{ $slot['time'] }}</span>
                                    </a>
                                @endif
                            @endforeach
                        @endif

                        @foreach ($column['items'] as $item)
                            @php $appointment = $item['appointment']; @endphp
                            <a href="#ag-{{ $appointment->id }}"
                               class="relative z-10 col-start-1 row-start-{{ $item['row'] }} row-span-{{ $item['span'] }} m-px overflow-hidden rounded-md border-l-4 px-1.5 py-0.5 text-xs leading-4 shadow-xs hover:brightness-95 {{ $appointment->status->blockClasses() }}">
                                <span class="font-semibold tabular-nums">{{ ClinicTime::format($appointment->scheduled_at, 'H:i') }}</span>
                                <span class="font-medium">{{ $appointment->patient->name }}</span>
                                <span class="block truncate opacity-80">{{ $appointment->procedure?->name ?? 'Procedimento a definir' }}</span>
                                <span class="sr-only">, {{ $appointment->status->label() }}</span>
                            </a>
                        @endforeach
                    </div>
                </section>
            @endforeach
        </div>
    @endif
</div>
