@php
    $stamp = fn ($date) => App\Support\ClinicTime::format($date, 'd/m/Y').' às '.App\Support\ClinicTime::format($date, 'H:i');
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
                    <h1 class="text-xl font-semibold tracking-tight">{{ $patient->name }}</h1>
                    <p class="mt-1 text-sm text-slate-600">
                        {{ $patient->age() }} {{ $patient->age() === 1 ? 'ano' : 'anos' }}
                        @if ($patient->sex) · {{ $patient->sex->label() }} @endif
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
            @can('viewClinicalRecords', $patient)
                <a href="{{ route('patients.record', $patient) }}" class="btn btn-secondary">Imprimir prontuário</a>
            @endcan
            @can('viewAny', App\Models\AuditLog::class)
                <a href="{{ route('audit.index', ['paciente' => $patient->id]) }}" class="btn btn-secondary">Ver acessos</a>
            @endcan
            @can('update', $patient)
                <a href="{{ route('patients.edit', $patient) }}" class="btn btn-secondary">Editar</a>
            @endcan
            @can('delete', $patient)
                <form method="POST" action="{{ route('patients.destroy', $patient) }}"
                      data-confirm="Arquivar o cadastro de {{ $patient->name }}? Ele sai da listagem, mas não é apagado. Os agendamentos em aberto dele serão cancelados.">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn btn-danger">Arquivar</button>
                </form>
            @endcan
        </div>
    </div>

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
                    <dd class="mt-0.5 text-sm tabular-nums">{{ $patient->formattedPhone() ?: '—' }}</dd>
                </div>
                <div class="min-w-0">
                    <dt class="text-xs text-slate-500">E-mail</dt>
                    <dd class="mt-0.5 text-sm break-words">{{ $patient->email ?: '—' }}</dd>
                </div>
            </dl>
        </section>

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

    @can('viewClinicalRecords', $patient)
        @include('patients._anamnesis')
    @endcan

    @if ($tab !== null)
        @php $tabClass = fn (bool $active) => 'rounded-lg px-3 py-1.5 text-sm font-medium whitespace-nowrap '.($active ? 'bg-teal-700 text-white' : 'text-slate-700 hover:bg-slate-100'); @endphp
        <nav id="historico" class="mt-6 flex gap-1 relative overflow-x-auto rounded-xl border border-slate-200 bg-white p-1" aria-label="Histórico do paciente">
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
