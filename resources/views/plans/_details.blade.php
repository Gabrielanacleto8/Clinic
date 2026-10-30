@php
    use App\Support\ClinicTime;
    use App\Support\LocalizedDecimal;
    use App\Support\Plural;
    use Carbon\CarbonImmutable;

    $total = $plan->total();
    $remaining = $plan->remainingSessions();
    $entry = $plan->relationLoaded('financialEntry') ? $plan->financialEntry : null;
    $billed = $entry?->installments->sum('amount') ?? 0;
    $paid = $entry?->installments->whereNotNull('paid_at')->sum('amount') ?? 0;

    $sold = $plan->wasSold();
    $kind = $plan->kindLabel();
    $totalSessions = (int) $plan->items->sum('sessions');
    $usedSessions = $totalSessions - $remaining;

    // Validade só importa enquanto o orçamento está em aberto.
    $daysLeft = $plan->isOpen() && $plan->valid_until
        ? (int) CarbonImmutable::parse(ClinicTime::today())->diffInDays($plan->valid_until, false)
        : null;

    $heading = 'text-xs font-semibold tracking-wide text-slate-500 uppercase';

    // O orçamento inteiro, na página dele e na janela da lista ($inList). Na lista, o
    // título não é o da página, "Converter em venda" usa o diálogo da lista (que recebe
    // o destino pelo botão) e recusar ou encerrar voltam para a lista.
    $inList ??= false;
    $titleTag = $inList ? 'h3' : 'h1';
@endphp
<div class="mt-3 flex flex-wrap items-center gap-4">
    <x-patients.avatar :patient="$patient" size="lg" />
    <div class="min-w-0">
        <{{ $titleTag }} class="flex flex-wrap items-center gap-2 text-2xl font-semibold tracking-tight">
            {{ $kind }} nº {{ $plan->id }}
            <span class="badge {{ $plan->status->badgeClasses() }}">{{ $plan->status->label() }}</span>
            @if ($plan->isExpired())
                <span class="badge bg-red-100 text-red-800">Validade vencida</span>
            @endif
        </{{ $titleTag }}>
        <p class="mt-1 text-sm text-slate-600">
            <a href="{{ route('patients.show', $patient) }}" class="font-medium text-slate-800 hover:underline">{{ $patient->name }}</a>
            · {{ Plural::of(count($plan->items), 'procedimento', 'procedimentos') }}, {{ Plural::of($totalSessions, 'sessão', 'sessões') }}
        </p>
    </div>
</div>

{{-- Pela largura do contêiner: na janela da lista e no celular, os procedimentos vêm
     primeiro e o valor com as ações logo abaixo deles; com espaço, o resumo fica à
     direita e acompanha a rolagem. --}}
<div class="@container mt-6">
    <div class="grid grid-cols-1 items-start gap-6 @3xl:grid-cols-3">
        <div class="@3xl:col-span-2 @3xl:col-start-1 @3xl:row-start-1">
            @include('plans._items')
        </div>

        <aside class="card overflow-hidden @3xl:sticky @3xl:top-4 @3xl:col-start-3 @3xl:row-span-2 @3xl:row-start-1" aria-labelledby="resumo">
            <div class="border-b border-slate-200 bg-slate-50 px-5 py-4">
                <h2 id="resumo" class="{{ $heading }}">{{ $sold ? 'Valor do pacote' : 'Valor do orçamento' }}</h2>
                <p class="mt-1 text-3xl font-semibold tracking-tight tabular-nums">{{ LocalizedDecimal::money($total) }}</p>
                @if ($totalSessions > 1)
                    <p class="mt-0.5 text-xs text-slate-500">em {{ Plural::of($totalSessions, 'sessão', 'sessões') }}</p>
                @endif
                @if ((float) $plan->discount() > 0)
                    <p class="mt-0.5 text-xs text-slate-500">Tabela {{ LocalizedDecimal::money($plan->listTotal()) }} · desconto {{ LocalizedDecimal::money($plan->discount()) }}</p>
                @endif
            </div>

            <div class="space-y-4 px-5 py-4">
                @if ($plan->isOpen())
                    <div>
                        <p class="text-xs text-slate-500">Válido até</p>
                        @if ($plan->valid_until)
                            <p class="text-sm font-medium">{{ $plan->valid_until->format('d/m/Y') }}</p>
                            <p @class([
                                'text-xs',
                                'text-red-700' => $daysLeft < 0,
                                'text-amber-700' => $daysLeft >= 0 && $daysLeft <= 3,
                                'text-slate-500' => $daysLeft > 3,
                            ])>
                                @if ($daysLeft < 0)
                                    Venceu há {{ Plural::of(-$daysLeft, 'dia', 'dias') }}
                                @elseif ($daysLeft === 0)
                                    Vence hoje
                                @else
                                    {{ $daysLeft === 1 ? 'Falta 1 dia' : 'Faltam '.$daysLeft.' dias' }}
                                @endif
                            </p>
                        @else
                            <p class="text-sm font-medium">Sem prazo</p>
                        @endif
                    </div>
                @endif

                @if ($sold)
                    <div>
                        <div class="flex items-baseline justify-between gap-2 text-sm">
                            <span class="text-slate-600">Sessões usadas</span>
                            <span class="font-medium tabular-nums">{{ $usedSessions }} de {{ $totalSessions }}</span>
                        </div>
                        <progress class="bar mt-1.5 text-slate-600" max="{{ max($totalSessions, 1) }}" value="{{ $usedSessions }}" aria-label="Sessões usadas">{{ $usedSessions }}</progress>
                        <p class="mt-1 text-xs text-slate-500">{{ $remaining === 0 ? 'Todas as sessões foram usadas.' : Plural::of($remaining, 'sessão restante', 'sessões restantes') }}</p>
                    </div>
                @endif

                @if ($entry)
                    <div>
                        <div class="flex items-baseline justify-between gap-2 text-sm">
                            <span class="text-slate-600">Recebido</span>
                            <span class="font-medium tabular-nums">{{ LocalizedDecimal::money($paid) }} de {{ LocalizedDecimal::money($billed) }}</span>
                        </div>
                        <progress class="bar mt-1.5 text-emerald-500" max="{{ max((float) $billed, 0.01) }}" value="{{ $paid }}" aria-label="Valor recebido">{{ LocalizedDecimal::money($paid) }}</progress>
                        <p class="mt-1 text-xs text-slate-500">
                            {{ $entry->payment_method->label() }} · {{ $entry->installments->count() > 1 ? $entry->installments->count().' parcelas' : 'à vista' }}
                            {{-- Pelo id na ficha, nunca pelo nome na URL (logs e histórico do navegador). --}}
                            · <a href="{{ route('patients.show', [$patient, 'aba' => 'financeiro']) }}#historico" class="text-slate-800 hover:underline">receber na ficha</a>
                        </p>
                    </div>
                @endif

                {{-- Ação principal em destaque; as demais logo abaixo. --}}
                <div class="space-y-2">
                    @can('approve', $plan)
                        <button type="button" class="btn btn-primary w-full" data-dialog-open="plan-approval"
                        @if ($inList)
                            data-dialog-action="{{ route('patients.plans.approve', [$patient, $plan]) }}"
                            data-dialog-values='@json(['plan_id' => $plan->id])'
                            data-dialog-summary="{{ LocalizedDecimal::money($total) }}"
                        @endif>Converter em venda</button>
                    @endcan
                    @if ($plan->isApproved() && $remaining > 0)
                        @can('create', App\Models\Appointment::class)
                            <a href="{{ route('appointments.create', ['paciente' => $patient->id, 'procedimento' => $plan->items->first(fn ($item) => $item->remainingSessions() > 0)?->procedure_id]) }}" class="btn btn-primary w-full">Agendar sessão</a>
                        @endcan
                    @endif
                    <div class="flex gap-2">
                        @can('update', $plan)
                            <a href="{{ route('patients.plans.edit', [$patient, $plan]) }}" class="btn btn-secondary flex-1">Editar</a>
                        @endcan
                        <a href="{{ route('patients.plans.print', [$patient, $plan]) }}" class="btn btn-secondary flex-1">Imprimir</a>
                    </div>
                </div>
            </div>

            {{-- Ações que encerram o orçamento: separadas e discretas, para não serem tocadas por engano. --}}
            @if (auth()->user()->can('decline', $plan) || auth()->user()->can('close', $plan))
                <div class="border-t border-slate-200 px-5 py-3">
                    @can('decline', $plan)
                        <form method="POST" action="{{ route('patients.plans.decline', [$patient, $plan]) }}" data-confirm="Marcar o orçamento nº {{ $plan->id }} como recusado pelo paciente?">
                            @csrf
                            @if ($inList)
                                <input type="hidden" name="voltar" value="lista">
                            @endif
                            <button type="submit" class="w-full cursor-pointer rounded-lg px-3 py-1.5 text-sm text-slate-600 hover:bg-slate-100 hover:text-slate-900">Paciente recusou</button>
                        </form>
                    @endcan
                    @can('close', $plan)
                        <form method="POST" action="{{ route('patients.plans.close', [$patient, $plan]) }}"
                              data-confirm="Encerrar o pacote nº {{ $plan->id }}? As {{ $remaining }} sessões restantes não poderão mais ser usadas. O financeiro não muda: devolução, se houver, é lançada à parte.">
                            @csrf
                            @if ($inList)
                                <input type="hidden" name="voltar" value="lista">
                            @endif
                            <button type="submit" class="w-full cursor-pointer rounded-lg px-3 py-1.5 text-sm text-red-700 hover:bg-red-50">Encerrar pacote</button>
                        </form>
                    @endcan
                </div>
            @endif
        </aside>

        <div class="space-y-6 @3xl:col-span-2 @3xl:col-start-1 @3xl:row-start-2">
            @if ($plan->notes)
                <section class="card p-5" aria-labelledby="observacoes">
                    <h2 id="observacoes" class="{{ $heading }}">Observações</h2>
                    <p class="mt-2 text-sm whitespace-pre-line">{{ $plan->notes }}</p>
                </section>
            @endif
        </div>
    </div>
</div>

