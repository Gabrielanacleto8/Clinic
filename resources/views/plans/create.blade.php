@php
    // Vindo da ficha do paciente, voltar e cancelar levam a ela; senão, à lista de orçamentos.
    $backUrl = $fromPatient && $patient ? route('patients.show', [$patient, 'aba' => 'orcamentos']).'#historico' : route('plans.index');
@endphp
<x-layouts.app title="Novo orçamento" section="plans.index">
    <div class="mx-auto max-w-3xl">
        <a href="{{ $backUrl }}" class="text-sm text-slate-600 hover:underline">← {{ $fromPatient && $patient ? $patient->name : 'Orçamentos' }}</a>
        <h1 class="mt-1 text-xl font-semibold tracking-tight">Novo orçamento</h1>

        <form method="POST" action="{{ route('plans.store') }}" class="mt-6 space-y-6" novalidate data-keepalive="{{ route('session.keepalive') }}">
            @include('plans._form', ['cancelUrl' => $backUrl])
        </form>
    </div>
</x-layouts.app>
