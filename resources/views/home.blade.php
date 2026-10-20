@php
    use App\Enums\AppointmentStatus;
    use App\Support\ClinicTime;
    use App\Support\LocalizedDecimal;

    $activeToday = $todayAppointments?->where('status', '!==', AppointmentStatus::Cancelled);

    // Indicadores, cada um com o módulo onde o número é detalhado. Só entram os que o perfil pode ver.
    $cards = array_filter([
        $todayAppointments !== null ? ['Atendimentos do dia', $activeToday->count(), $activeToday->whereIn('status', AppointmentStatus::open())->count().' aguardando', route('agenda.index')] : null,
        isset($totals['patients']) ? ['Pacientes cadastrados', number_format($totals['patients'], 0, ',', '.'), 'Cadastros ativos', route('patients.index')] : null,
        isset($totals['treatments']) ? ['Atendimentos realizados', $totals['treatments'], 'Registrados neste mês', route('treatments.index', ['de' => $today->startOfMonth()->format('Y-m-d')])] : null,
        isset($totals['revenue']) ? ['Faturamento do período', LocalizedDecimal::money($totals['revenue']), 'Receitas lançadas neste mês', route('finance.index')] : null,
        isset($totals['low_stock']) ? ['Estoque baixo', $totals['low_stock'], $totals['low_stock'] === 1 ? 'Produto no mínimo ou abaixo' : 'Produtos no mínimo ou abaixo', route('products.index', ['situacao' => 'baixo'])] : null,
        isset($totals['expiring_batches']) ? ['Próximos do vencimento', $totals['expiring_batches'], 'Lotes vencidos ou vencendo', route('products.index', ['situacao' => 'vencendo'])] : null,
    ]);
@endphp
<x-layouts.app title="Início">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold tracking-tight">Início</h1>
            <p class="mt-1 text-sm text-slate-600">{{ ucfirst($today->locale('pt_BR')->translatedFormat('l, j \d\e F \d\e Y')) }}</p>
        </div>
        <div class="flex flex-wrap gap-2">
            @can('create', App\Models\Appointment::class)
                <a href="{{ route('appointments.create') }}" @class(['btn', $treatmentForm ? 'btn-secondary' : 'btn-primary'])>Novo agendamento</a>
            @endcan
            @if ($treatmentForm)
                <button type="button" class="btn btn-primary" data-dialog-open="{{ $treatmentForm['scope'] }}">Registrar atendimento</button>
            @endif
        </div>
    </div>

    @if ($cards !== [])
        <dl class="mt-6 grid grid-cols-2 gap-4 lg:grid-cols-3">
            @foreach ($cards as [$label, $value, $caption, $url])
                <div class="card px-4 py-3">
                    <dt class="text-xs text-slate-500">{{ $label }}</dt>
                    <dd class="mt-0.5 text-2xl font-semibold tabular-nums">
                        @if ($url)
                            <a href="{{ $url }}" class="hover:text-teal-800 hover:underline">{{ $value }}</a>
                        @else
                            {{ $value }}
                        @endif
                    </dd>
                    <dd class="mt-0.5 text-xs text-slate-500">{{ $caption }}</dd>
                </div>
            @endforeach
        </dl>
    @endif

    @if ($todayAppointments !== null)
        <section class="card mt-6" aria-labelledby="agenda-de-hoje">
            <div class="flex flex-wrap items-center justify-between gap-2 px-4 pt-5 pb-3 sm:px-6">
                <h2 id="agenda-de-hoje" class="text-sm font-semibold tracking-wide text-slate-500 uppercase">Agenda de hoje</h2>
                <a href="{{ route('agenda.index') }}" class="text-sm text-teal-800 hover:underline">Abrir a agenda</a>
            </div>
            @if ($todayAppointments->isEmpty())
                <p class="px-4 py-10 text-center text-sm text-slate-500 sm:px-6">Nenhum agendamento para hoje.</p>
            @else
                @include('appointments._list', ['appointments' => $todayAppointments, 'origin' => App\Enums\TreatmentOrigin::Home])
            @endif
        </section>

        <section class="card mt-6" aria-labelledby="proximos-agendamentos">
            <h2 id="proximos-agendamentos" class="px-4 pt-5 pb-3 text-sm font-semibold tracking-wide text-slate-500 uppercase sm:px-6">Próximos agendamentos</h2>
            @if ($upcomingAppointments->isEmpty())
                <p class="px-4 pt-1 pb-6 text-sm text-slate-600 sm:px-6">Nada agendado para os próximos dias.</p>
            @else
                <ul class="divide-y divide-slate-100">
                    @foreach ($upcomingAppointments as $appointment)
                        <li class="flex flex-wrap items-center gap-x-6 gap-y-1 px-4 py-3 sm:px-6">
                            <a href="{{ route('agenda.index', ['data' => ClinicTime::format($appointment->scheduled_at, 'Y-m-d')]) }}"
                               class="w-36 shrink-0 text-sm font-medium text-teal-800 tabular-nums hover:underline">
                                {{ ClinicTime::format($appointment->scheduled_at, 'd/m') }} às {{ ClinicTime::format($appointment->scheduled_at, 'H:i') }}
                            </a>
                            <p class="min-w-0 flex-1 basis-48 text-sm">
                                <span class="font-medium">{{ $appointment->patient->name }}</span>
                                <span class="text-slate-600">· {{ $appointment->procedure?->name ?? 'Procedimento a definir' }} · {{ $appointment->professional->name }}</span>
                            </p>
                            <span class="badge {{ $appointment->status->badgeClasses() }}">{{ $appointment->status->label() }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    @endif

    @if ($lowStockProducts !== null)
        <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-2">
            <section class="card p-6" aria-labelledby="estoque-baixo">
                <h2 id="estoque-baixo" class="text-sm font-semibold tracking-wide text-slate-500 uppercase">Produtos com estoque baixo</h2>
                @if ($lowStockProducts->isEmpty())
                    <p class="mt-4 text-sm text-slate-600">Nenhum produto abaixo do mínimo.</p>
                @else
                    <ul class="mt-4 divide-y divide-slate-100">
                        @foreach ($lowStockProducts as $product)
                            <li class="flex items-center justify-between gap-3 py-2 text-sm">
                                <a href="{{ route('products.show', $product) }}" class="min-w-0 truncate font-medium text-teal-800 hover:underline">{{ $product->name }}</a>
                                <span class="shrink-0 whitespace-nowrap text-red-700 tabular-nums">
                                    {{ LocalizedDecimal::quantity($product->available_quantity ?? 0) }} de {{ LocalizedDecimal::quantity($product->minimum_stock) }} {{ $product->unit->symbol() }}
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            <section class="card p-6" aria-labelledby="vencimento">
                <h2 id="vencimento" class="text-sm font-semibold tracking-wide text-slate-500 uppercase">Produtos próximos do vencimento</h2>
                @if ($expiringBatches->isEmpty())
                    <p class="mt-4 text-sm text-slate-600">Nenhum lote vencendo nos próximos {{ config('clinic.stock.expiry_alert_days') }} dias.</p>
                @else
                    <ul class="mt-4 divide-y divide-slate-100">
                        @foreach ($expiringBatches as $batch)
                            <li class="flex items-center justify-between gap-3 py-2 text-sm">
                                <a href="{{ route('products.show', $batch->product_id) }}" class="min-w-0 truncate font-medium text-teal-800 hover:underline">
                                    {{ $batch->product->name }} <span class="font-normal text-slate-500">· lote {{ $batch->batch_code }}</span>
                                </a>
                                <span @class(['shrink-0 whitespace-nowrap tabular-nums', 'text-red-700' => $batch->isExpired(), 'text-amber-700' => ! $batch->isExpired()])>
                                    {{ $batch->isExpired() ? 'Venceu em' : 'Vence em' }} {{ $batch->expires_on->format('d/m/Y') }}
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        </div>
    @endif

    @if ($cards === [] && $todayAppointments === null && $lowStockProducts === null)
        <p class="mt-6 text-sm text-slate-600">Seu perfil ainda não tem acesso a nenhum módulo. Fale com o administrador.</p>
    @endif

    @if ($treatmentForm)
        @include('treatments._dialog', ['origin' => App\Enums\TreatmentOrigin::Home])
    @endif
</x-layouts.app>
