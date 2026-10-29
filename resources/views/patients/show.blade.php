@php
    use App\Support\ClinicTime;
    use App\Support\LocalizedDecimal;

    $stamp = fn ($date) => ClinicTime::format($date, 'd/m/Y').' às '.ClinicTime::format($date, 'H:i');
    $clinical = auth()->user()->can('viewClinicalRecords', $patient);
    $pending = $financialSummary ? (float) $financialSummary->pending : 0.0;
    $overdue = $financialSummary ? (float) $financialSummary->overdue : 0.0;
    $address = collect([
        trim(($patient->street ?? '').($patient->number ? ', '.$patient->number : '')),
        $patient->complement,
        $patient->district,
        trim(($patient->city ?? '').($patient->state ? ' / '.$patient->state : ''), ' /'),
        $patient->formattedZipCode() ? 'CEP '.$patient->formattedZipCode() : null,
    ])->filter()->implode(' · ');
@endphp
<x-layouts.app :title="$patient->name">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <a href="{{ route('patients.index') }}" class="text-sm text-slate-600 hover:underline">← Pacientes</a>
            <div class="mt-2 flex items-center gap-4">
                <x-patients.avatar :patient="$patient" size="lg" />
                <div>
                    <h1 class="text-xl font-semibold tracking-tight">
                        {{ $patient->name }}
                        @if ($patient->isMinor())
                            <span class="badge ml-1 bg-sky-100 align-middle text-sky-800">Menor de idade</span>
                        @endif
                    </h1>
                    <p class="mt-1 text-sm text-slate-600">
                        {{ $patient->age() }} {{ $patient->age() === 1 ? 'ano' : 'anos' }}
                        @if ($patient->sex) · {{ $patient->sex->label() }} @endif
                        @if ($pending > 0)
                            · <span @class(['badge', 'bg-red-100 text-red-800' => $overdue > 0, 'bg-amber-100 text-amber-800' => $overdue <= 0])>
                                Pendente {{ LocalizedDecimal::money($pending) }}@if ($overdue > 0) · vencido {{ LocalizedDecimal::money($overdue) }}@endif
                            </span>
                        @endif
                    </p>
                    @can('update', $patient)
                        <button type="button" class="mt-1 cursor-pointer text-sm font-medium text-teal-800 hover:underline" data-dialog-open="patient-avatar">
                            {{ $patient->avatar_path ? 'Trocar foto' : 'Adicionar foto' }}
                        </button>
                    @endcan
                </div>
            </div>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            @can('create', App\Models\Appointment::class)
                <a href="{{ route('appointments.create', ['paciente' => $patient->id]) }}" class="btn btn-primary">Agendar</a>
            @endcan
            @can('update', $patient)
                <a href="{{ route('patients.edit', $patient) }}" class="btn btn-secondary">Editar</a>
            @endcan
            @if ($clinical || auth()->user()->can('viewAny', App\Models\AuditLog::class) || auth()->user()->can('create', App\Models\TreatmentPlan::class))
                {{-- Ações de consulta menos frequentes ficam num menu: o arquivamento está no fim da edição. --}}
                <details class="relative" data-menu>
                    <summary class="btn btn-secondary cursor-pointer list-none">Mais <x-icon name="chevron-down" class="size-4" /></summary>
                    <div class="absolute right-0 z-10 mt-1 w-56 rounded-lg border border-slate-200 bg-white py-1 shadow-lg">
                        @if ($clinical)
                            <a href="{{ route('patients.record', $patient) }}" class="block px-3 py-2 text-sm hover:bg-slate-50">Imprimir prontuário</a>
                            <a href="{{ route('patients.consent-term', $patient) }}" class="block px-3 py-2 text-sm hover:bg-slate-50">Termo de consentimento</a>
                        @endif
                        @can('create', App\Models\TreatmentPlan::class)
                            <a href="{{ route('plans.create', ['paciente' => $patient->id]) }}" class="block px-3 py-2 text-sm hover:bg-slate-50">Novo orçamento</a>
                        @endcan
                        @can('viewAny', App\Models\AuditLog::class)
                            <a href="{{ route('audit.index', ['paciente' => $patient->id]) }}" class="block px-3 py-2 text-sm hover:bg-slate-50">Ver acessos</a>
                        @endcan
                    </div>
                </details>
            @endif
        </div>
    </div>

    @if ($clinical)
        {{-- Alergias e contraindicações antes de tudo: é o que o profissional procura ao abrir a ficha. --}}
        {{-- Sem ficha, o aviso já traz o botão de preencher: o cartão da ficha só aparece depois. --}}
        <x-health-alerts :anamnesis="$patient->anamnesis" class="mt-4"
                         :fill-url="auth()->user()->can('updateClinicalRecords', $patient) ? route('patients.anamnesis.edit', $patient) : null" />
    @endif

    @isset($upcomingAppointments)
        <section class="card mt-6 p-6" aria-labelledby="agendamentos-do-paciente">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 id="agendamentos-do-paciente" class="text-sm font-semibold tracking-wide text-slate-500 uppercase">Agendamentos</h2>
                <p class="text-xs text-slate-500">
                    {{ $noShowCount === 0 ? 'Nenhuma falta' : $noShowCount.' '.($noShowCount === 1 ? 'falta' : 'faltas') }}
                    · <a href="{{ route('patients.show', [$patient, 'aba' => 'agendamentos']) }}#historico" class="text-teal-800 hover:underline">Ver todos</a>
                </p>
            </div>
            @if ($upcomingAppointments->isNotEmpty())
                <ul class="mt-3 divide-y divide-slate-100">
                    @foreach ($upcomingAppointments as $appointment)
                        <li class="flex flex-wrap items-center gap-x-4 gap-y-1 py-2 text-sm">
                            <span class="font-medium tabular-nums">{{ ClinicTime::format($appointment->scheduled_at) }}</span>
                            <span class="min-w-0 flex-1">{{ $appointment->procedure?->name ?? 'Sem procedimento definido' }} · {{ $appointment->professional->name }}</span>
                            <span class="badge {{ $appointment->status->badgeClasses() }}">{{ $appointment->status->label() }}</span>
                            @can('viewAny', App\Models\Appointment::class)
                                <a href="{{ route('agenda.index', ['data' => ClinicTime::format($appointment->scheduled_at, 'Y-m-d')]) }}" class="text-teal-800 hover:underline">Agenda</a>
                            @endcan
                        </li>
                    @endforeach
                </ul>
            @elseif ($pendingReturn)
                <p class="mt-3 flex flex-wrap items-center gap-3 text-sm">
                    <span @class(['font-medium', 'text-amber-800' => $pendingReturn->lessThan(ClinicTime::today())])>
                        Retorno previsto em {{ $pendingReturn->format('d/m/Y') }}{{ $pendingReturn->lessThan(ClinicTime::today()) ? ', ainda não agendado' : '' }}
                    </span>
                    @can('create', App\Models\Appointment::class)
                        <a href="{{ route('appointments.create', ['paciente' => $patient->id, 'data' => $pendingReturn->format('Y-m-d')]) }}" class="btn btn-secondary">Agendar retorno</a>
                    @endcan
                </p>
            @else
                <p class="mt-3 text-sm text-slate-600">Nenhum agendamento em aberto.</p>
            @endif
        </section>
    @endisset

    <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-2">
        <section class="card p-6">
            <h2 class="text-sm font-semibold tracking-wide text-slate-500 uppercase">Identificação</h2>
            <dl class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <dt class="text-xs text-slate-500">CPF</dt>
                    <dd class="mt-0.5 text-sm tabular-nums">{{ $patient->formattedCpf() }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-slate-500">Data de nascimento</dt>
                    <dd class="mt-0.5 text-sm">{{ $patient->birth_date->format('d/m/Y') }}</dd>
                </div>
            </dl>
        </section>

        <section class="card p-6">
            <h2 class="text-sm font-semibold tracking-wide text-slate-500 uppercase">Contato</h2>
            <dl class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <dt class="text-xs text-slate-500">Telefone</dt>
                    <dd class="mt-0.5 text-sm tabular-nums">
                        @if ($patient->phone)
                            <a href="tel:+55{{ $patient->phoneDigits() }}" class="text-teal-800 hover:underline">{{ $patient->formattedPhone() }}</a>
                            · <a href="https://wa.me/55{{ $patient->phoneDigits() }}" target="_blank" rel="noopener" class="text-teal-800 hover:underline">WhatsApp</a>
                        @else
                            —
                        @endif
                    </dd>
                </div>
                <div class="min-w-0">
                    <dt class="text-xs text-slate-500">E-mail</dt>
                    <dd class="mt-0.5 text-sm break-words">
                        @if ($patient->email)
                            <a href="mailto:{{ $patient->email }}" class="text-teal-800 hover:underline">{{ $patient->email }}</a>
                        @else
                            —
                        @endif
                    </dd>
                </div>
            </dl>
        </section>

        @if ($patient->isMinor() || $patient->guardian_name)
            <section class="card p-6 lg:col-span-2">
                <h2 class="text-sm font-semibold tracking-wide text-slate-500 uppercase">Responsável legal</h2>
                @if ($patient->guardian_name)
                    <dl class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-4">
                        <div class="sm:col-span-2">
                            <dt class="text-xs text-slate-500">Nome</dt>
                            <dd class="mt-0.5 text-sm">{{ $patient->guardian_name }}{{ $patient->guardian_relationship ? ' ('.$patient->guardian_relationship.')' : '' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-slate-500">CPF</dt>
                            <dd class="mt-0.5 text-sm tabular-nums">{{ $patient->guardian_cpf ? App\Support\Cpf::format($patient->guardian_cpf) : '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-slate-500">Telefone</dt>
                            <dd class="mt-0.5 text-sm tabular-nums">
                                @if ($patient->guardian_phone)
                                    <a href="tel:+55{{ $patient->guardian_phone }}" class="text-teal-800 hover:underline">{{ $patient->formattedGuardianPhone() }}</a>
                                @else
                                    —
                                @endif
                            </dd>
                        </div>
                    </dl>
                @else
                    <p class="mt-4 text-sm text-amber-800">Paciente menor de idade sem responsável legal cadastrado. Complete o cadastro.</p>
                @endif
            </section>
        @endif

        <section class="card p-6 lg:col-span-2">
            <h2 class="text-sm font-semibold tracking-wide text-slate-500 uppercase">Endereço</h2>
            <p class="mt-4 text-sm">{{ $address ?: '—' }}</p>
        </section>

        <section class="card p-6 lg:col-span-2">
            <h2 class="text-sm font-semibold tracking-wide text-slate-500 uppercase">Uso de imagem em divulgação</h2>
            <p class="mt-4 text-sm">
                @if ($patient->image_use_consented_at)
                    <span class="badge bg-emerald-100 text-emerald-800">Autorizado</span>
                    <span class="text-slate-600">em {{ App\Support\ClinicTime::format($patient->image_use_consented_at, 'd/m/Y') }}</span>
                @else
                    <span class="badge bg-amber-100 text-amber-800">Não autorizado</span>
                    <span class="text-slate-600">As fotos ficam só no prontuário.</span>
                @endif
            </p>
        </section>

        <section class="card p-6 lg:col-span-2">
            <h2 class="text-sm font-semibold tracking-wide text-slate-500 uppercase">Observações</h2>
            <p class="mt-4 text-sm whitespace-pre-line">{{ $patient->notes ?: '—' }}</p>
        </section>
    </div>

    @if ($clinical && $patient->anamnesis)
        @include('patients._anamnesis')
    @endif

    @if ($tab !== null)
        @php $tabClass = fn (bool $active) => 'rounded-lg px-3 py-1.5 text-sm font-medium whitespace-nowrap '.($active ? 'bg-brand text-on-brand' : 'text-slate-700 hover:bg-slate-100'); @endphp
        <nav id="historico" class="mt-6 flex gap-1 relative overflow-x-auto rounded-xl border border-slate-200 bg-white p-1" aria-label="Histórico do paciente" data-tabs>
            @foreach ($tabs as $key => $label)
                <a href="{{ route('patients.show', [$patient, 'aba' => $key]) }}#historico" class="{{ $tabClass($tab === $key) }}" @if ($tab === $key) aria-current="page" @endif>{{ $label }}</a>
            @endforeach
        </nav>

        @include('patients.tabs.'.$tab)
    @endif

    @can('update', $patient)
        @include('patients._avatar_dialog')
    @endcan

    <p class="mt-6 text-xs text-slate-500">
        Cadastrado em {{ $stamp($patient->created_at) }}
        @if ($patient->creator) por {{ $patient->creator->name }} @endif
        · Última atualização em {{ $stamp($patient->updated_at) }}
    </p>
</x-layouts.app>
