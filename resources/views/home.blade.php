@php
    use App\Enums\AppointmentStatus;
    use App\Support\ClinicTime;
    use App\Support\LocalizedDecimal;

    $activeToday = $todayAppointments?->where('status', '!==', AppointmentStatus::Cancelled);
    $plural = fn (int $total, string $one, string $many) => $total.' '.($total === 1 ? $one : $many);

    $todayDate = $today->format('Y-m-d');
    $stockAlerts = isset($totals['low_stock']) ? $totals['low_stock'] + $totals['expiring_batches'] + $totals['units_to_discard'] : null;

    // Visão operacional: só contagens do dia, nenhum valor em dinheiro. Cada indicador
    // leva ao lugar onde é detalhado e só entra para o perfil que pode vê-lo.
    $cards = array_filter([
        $todayAppointments !== null ? ['Agendamentos de hoje', $activeToday->count(), $plural($activeToday->whereIn('status', AppointmentStatus::open())->count(), 'em aberto', 'em aberto'), route('agenda.index')] : null,
        isset($totals['treatments']) ? ['Atendimentos de hoje', $totals['treatments'], 'Registrados, sem os anulados', route('treatments.index', array_filter(['de' => $todayDate, 'ate' => $todayDate, 'profissional' => $attends && ! $showAll ? auth()->id() : null]))] : null,
        $pending !== null ? ['Pendentes de registro', $pending['total'], 'Dias anteriores sem registro', $pending['total'] > 0 ? '#pendentes-de-registro' : route('agenda.index')] : null,
        $stockAlerts !== null ? ['Alertas de estoque', $stockAlerts, $stockAlerts === 0 ? 'Nada a resolver' : 'Baixo, vencendo ou a descartar', $stockAlerts > 0 ? '#alertas-de-estoque' : route('products.index')] : null,
    ]);
@endphp
<x-layouts.app title="Início">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold tracking-tight">Início</h1>
            <p class="mt-1 text-sm text-slate-600">{{ ucfirst($today->locale('pt_BR')->translatedFormat('l, j \d\e F \d\e Y')) }}</p>
        </div>
        <div class="flex flex-wrap gap-2">
            @can('create', App\Models\Patient::class)
                <a href="{{ route('patients.create') }}" class="btn btn-secondary">Novo paciente</a>
            @endcan
            @can('create', App\Models\Appointment::class)
                <a href="{{ route('appointments.create') }}" @class(['btn', $treatmentForm ? 'btn-secondary' : 'btn-primary'])>Novo agendamento</a>
            @endcan
            @if ($treatmentForm)
                <button type="button" class="btn btn-primary" data-dialog-open="{{ $treatmentForm['scope'] }}">Registrar atendimento</button>
            @endif
        </div>
    </div>

    @can('viewAny', App\Models\Patient::class)
        {{-- Achar o paciente é a ação mais frequente do balcão: fica a um toque do Início. --}}
        <form method="GET" action="{{ route('patients.index') }}" class="mt-6 flex gap-2" role="search">
            <label for="busca-paciente" class="sr-only">Buscar paciente</label>
            <input id="busca-paciente" name="q" type="search" maxlength="100" class="input" placeholder="Buscar paciente por nome, CPF ou telefone" autocomplete="off">
            <button type="submit" class="btn btn-secondary">Buscar</button>
        </form>
    @endcan

    {{-- No celular, a agenda do dia vem antes dos indicadores: é o que a recepção abre às 8h. --}}
    <div class="mt-6 flex flex-col gap-6">
        @if ($cards !== [])
            <dl class="order-last grid grid-cols-2 gap-4 sm:order-none lg:grid-cols-4">
                @foreach ($cards as [$label, $value, $caption, $url])
                    <div class="card relative px-4 py-3 hover:bg-slate-50">
                        <dt class="text-xs text-slate-500">{{ $label }}</dt>
                        <dd class="mt-0.5 text-2xl font-semibold tabular-nums">
                            {{-- O link cobre o cartão inteiro. --}}
                            <a href="{{ $url }}" class="after:absolute after:inset-0 hover:text-slate-800">{{ $value }}</a>
                        </dd>
                        <dd class="mt-0.5 text-xs text-slate-500">{{ $caption }}</dd>
                    </div>
                @endforeach
            </dl>
        @endif

        @if ($pending !== null && $pending['total'] > 0)
            <section class="card border-amber-300" aria-labelledby="pendentes-de-registro">
                <div class="flex flex-wrap items-center justify-between gap-2 px-4 pt-5 pb-3 sm:px-6">
                    <h2 id="pendentes-de-registro" class="scroll-mt-20 text-sm font-semibold tracking-wide text-amber-800 uppercase">
                        Pendentes de registro · {{ $plural($pending['total'], 'agendamento', 'agendamentos') }}
                    </h2>
                    <p class="text-sm text-slate-600">Dias anteriores sem atendimento registrado nem falta marcada: estoque e cobrança ficaram sem lançar.</p>
                </div>
                @include('appointments._list', ['appointments' => $pending['list'], 'origin' => App\Enums\TreatmentOrigin::Home, 'showDate' => true])
                @if ($pending['total'] > $pending['list']->count())
                    <p class="border-t border-slate-100 px-4 py-3 text-sm text-slate-600 sm:px-6">
                        Mostrando os {{ $pending['list']->count() }} mais antigos.
                        <a href="{{ route('agenda.index', ['data' => $pending['list']->first()->localDay()]) }}#dia" class="text-slate-800 hover:underline">Abrir a agenda nesse dia</a>
                    </p>
                @endif
            </section>
        @endif

        @if ($todayAppointments !== null)
            <section class="card" aria-labelledby="agenda-de-hoje">
                <div class="flex flex-wrap items-center justify-between gap-2 px-4 pt-5 pb-3 sm:px-6">
                    <h2 id="agenda-de-hoje" class="text-sm font-semibold tracking-wide text-slate-500 uppercase">
                        Agenda de hoje{{ $attends && ! $showAll ? ' · só os meus' : '' }}
                    </h2>
                    <p class="flex flex-wrap gap-x-4 text-sm">
                        @if ($attends)
                            {{-- Quem atende abre com a própria agenda; a de todos fica a um clique. --}}
                            <a href="{{ route('home', $showAll ? [] : ['todos' => 1]) }}" class="text-slate-800 hover:underline">{{ $showAll ? 'Só os meus' : 'Ver todos os profissionais' }}</a>
                        @endif
                        <a href="{{ route('agenda.index') }}" class="text-slate-800 hover:underline">Abrir a agenda</a>
                    </p>
                </div>
                @if ($todayAppointments->isEmpty())
                    <p class="px-4 py-10 text-center text-sm text-slate-500 sm:px-6">Nenhum agendamento para hoje.</p>
                @else
                    @include('appointments._list', ['appointments' => $todayAppointments, 'origin' => App\Enums\TreatmentOrigin::Home])
                @endif
            </section>

            <section class="card" aria-labelledby="proximos-agendamentos">
                <div class="flex flex-wrap items-center justify-between gap-2 px-4 pt-5 pb-3 sm:px-6">
                    <h2 id="proximos-agendamentos" class="text-sm font-semibold tracking-wide text-slate-500 uppercase">Próximos agendamentos</h2>
                    <a href="{{ route('agenda.index', ['data' => $today->addDay()->format('Y-m-d')]) }}#dia" class="text-sm text-slate-800 hover:underline">Ver amanhã na agenda</a>
                </div>
                @if ($upcomingAppointments->isEmpty())
                    <p class="px-4 pt-1 pb-6 text-sm text-slate-600 sm:px-6">Nada agendado para os próximos dias.</p>
                @else
                    <ul class="divide-y divide-slate-100">
                        @foreach ($upcomingAppointments as $appointment)
                            <li class="flex flex-wrap items-center gap-x-6 gap-y-1 px-4 py-3 sm:px-6">
                                <a href="{{ route('agenda.index', ['data' => $appointment->localDay()]) }}#dia"
                                   class="w-36 shrink-0 text-sm font-medium text-slate-800 tabular-nums hover:underline">
                                    {{ ClinicTime::format($appointment->scheduled_at, 'd/m') }} às {{ ClinicTime::format($appointment->scheduled_at, 'H:i') }}
                                </a>
                                <div class="min-w-0 flex-1 basis-48 text-sm">
                                    <p>
                                        <span class="font-medium">{{ $appointment->patient->name }}</span>
                                        <span class="text-slate-600">· {{ $appointment->procedure?->name ?? 'Procedimento a definir' }} · {{ $appointment->professional->name }}</span>
                                    </p>
                                    @if ($appointment->patient->phone)
                                        @can('view', $appointment->patient)
                                            {{-- Para confirmar o horário de amanhã sem abrir a ficha. --}}
                                            <p class="flex flex-wrap gap-x-3 text-xs">
                                                <a href="tel:+55{{ $appointment->patient->phone }}" class="text-slate-800 tabular-nums hover:underline">{{ $appointment->patient->formattedPhone() }}</a>
                                                <a href="https://wa.me/55{{ $appointment->patient->phone }}" target="_blank" rel="noopener noreferrer" class="text-slate-800 hover:underline">WhatsApp</a>
                                            </p>
                                        @endcan
                                    @endif
                                </div>
                                <span class="badge {{ $appointment->status->badgeClasses() }}">{{ $appointment->status->label() }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        @endif

        @if ($returns !== null && $returns['total'] > 0)
            <section class="card" aria-labelledby="retornos">
                <div class="flex flex-wrap items-center justify-between gap-2 px-4 pt-5 pb-3 sm:px-6">
                    <h2 id="retornos" class="text-sm font-semibold tracking-wide text-slate-500 uppercase">Retornos previstos sem agendamento · {{ $returns['total'] }}</h2>
                    <p class="text-sm text-slate-600">Previstos no registro do atendimento e ainda não marcados.</p>
                </div>
                <ul class="divide-y divide-slate-100">
                    @foreach ($returns['list'] as $treatment)
                        <li class="flex flex-wrap items-center gap-x-6 gap-y-1 px-4 py-3 sm:px-6">
                            <span @class(['w-36 shrink-0 text-sm font-medium tabular-nums', 'text-red-700' => $treatment->return_date->format('Y-m-d') < $today->format('Y-m-d')])>
                                {{ $treatment->return_date->format('d/m/Y') }}
                            </span>
                            <p class="min-w-0 flex-1 basis-48 text-sm">
                                <a href="{{ route('patients.show', $treatment->patient) }}" class="font-medium text-slate-800 hover:underline">{{ $treatment->patient->name }}</a>
                                <span class="text-slate-600">· retorno de {{ $treatment->procedure->name }} ({{ ClinicTime::format($treatment->performed_at, 'd/m') }})</span>
                            </p>
                            @can('create', App\Models\Appointment::class)
                                <a href="{{ route('appointments.create', ['paciente' => $treatment->patient_id, 'data' => max($treatment->return_date->format('Y-m-d'), $today->format('Y-m-d'))]) }}" class="btn btn-secondary px-2.5 py-1 text-xs">Agendar</a>
                            @endcan
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        @if ($lowStockProducts !== null)
            <section class="card scroll-mt-20" id="alertas-de-estoque" aria-labelledby="alertas-de-estoque-titulo">
                <div class="flex flex-wrap items-center justify-between gap-2 px-4 pt-5 pb-3 sm:px-6">
                    <h2 id="alertas-de-estoque-titulo" class="text-sm font-semibold tracking-wide text-slate-500 uppercase">Alertas de estoque</h2>
                    <a href="{{ route('products.index') }}" class="text-sm text-slate-800 hover:underline">Abrir o estoque</a>
                </div>
                <div class="grid grid-cols-1 gap-x-6 gap-y-5 px-4 pb-5 sm:px-6 lg:grid-cols-3">
                    <div>
                        <h3 class="flex items-baseline justify-between gap-2 text-sm font-medium">
                            Estoque baixo
                            @if ($totals['low_stock'] > 0)
                                <a href="{{ route('products.index', ['situacao' => 'baixo']) }}" class="text-xs font-normal text-slate-800 hover:underline">Ver {{ $totals['low_stock'] }}</a>
                            @endif
                        </h3>
                        @if ($lowStockProducts->isEmpty())
                            <p class="mt-2 text-sm text-slate-600">Nenhum produto abaixo do mínimo.</p>
                        @else
                            <ul class="mt-2 divide-y divide-slate-100">
                                @foreach ($lowStockProducts as $product)
                                    <li class="flex items-center justify-between gap-3 py-2 text-sm">
                                        <a href="{{ route('products.show', $product) }}" class="min-w-0 truncate text-slate-800 hover:underline">{{ $product->name }}</a>
                                        <span class="shrink-0 whitespace-nowrap text-red-700 tabular-nums">
                                            {{ LocalizedDecimal::quantity($product->available_quantity ?? 0) }} de {{ LocalizedDecimal::quantity($product->minimum_stock) }} {{ $product->unit->symbol() }}
                                        </span>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>

                    <div>
                        <h3 class="flex items-baseline justify-between gap-2 text-sm font-medium">
                            Vencidos ou vencendo
                            @if ($totals['expiring_batches'] > 0)
                                <a href="{{ route('stock.batches.index', ['situacao' => 'vencendo']) }}" class="text-xs font-normal text-slate-800 hover:underline">Ver {{ $totals['expiring_batches'] }}</a>
                            @endif
                        </h3>
                        @if ($expiringBatches->isEmpty())
                            <p class="mt-2 text-sm text-slate-600">Nenhum lote vencido nem vencendo nos próximos {{ config('clinic.stock.expiry_alert_days') }} dias.</p>
                        @else
                            <ul class="mt-2 divide-y divide-slate-100">
                                @foreach ($expiringBatches as $batch)
                                    <li class="flex items-center justify-between gap-3 py-2 text-sm">
                                        {{-- Leva ao lote, onde a perda é lançada. --}}
                                        <a href="{{ route('stock.batches.show', $batch) }}" class="min-w-0 truncate text-slate-800 hover:underline">
                                            {{ $batch->product->name }} <span class="text-slate-500">· lote {{ $batch->batch_code }}</span>
                                        </a>
                                        <span class="shrink-0 text-xs text-slate-500 tabular-nums">{{ $batch->expires_on->format('d/m/Y') }}@include('stock._expiry_badge', ['batch' => $batch])</span>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>

                    <div>
                        <h3 class="flex items-baseline justify-between gap-2 text-sm font-medium">
                            Unidades a descartar
                            @if ($totals['units_to_discard'] > 0)
                                <a href="{{ route('stock.units.index') }}" class="text-xs font-normal text-slate-800 hover:underline">Ver {{ $totals['units_to_discard'] }}</a>
                            @endif
                        </h3>
                        @if ($unitsToDiscard->isEmpty())
                            <p class="mt-2 text-sm text-slate-600">Nenhuma unidade aberta aguardando descarte.</p>
                        @else
                            <ul class="mt-2 divide-y divide-slate-100">
                                @foreach ($unitsToDiscard as $unit)
                                    <li class="py-2 text-sm">
                                        {{-- Leva à unidade, onde o descarte é lançado. --}}
                                        <a href="{{ route('stock.units.show', $unit) }}" class="block truncate text-slate-800 hover:underline">
                                            {{ $unit->batch->product->name }} <span class="text-slate-500">· lote {{ $unit->batch->batch_code }}</span>
                                        </a>
                                        <p class="text-xs text-red-700">{{ $unit->discardReason() }}</p>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                </div>
            </section>
        @endif
    </div>

    @if ($cards === [] && $todayAppointments === null && $lowStockProducts === null)
        <p class="mt-6 text-sm text-slate-600">Seu perfil ainda não tem acesso a nenhum módulo. Fale com o administrador.</p>
    @endif

    @if ($treatmentForm)
        @include('treatments._dialog', ['origin' => App\Enums\TreatmentOrigin::Home])
    @endif
</x-layouts.app>
