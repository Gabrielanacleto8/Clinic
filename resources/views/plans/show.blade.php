@php
    $kind = $plan->kindLabel();
@endphp
<x-layouts.app :title="$kind.' nº '.$plan->id.' · '.$patient->name" section="plans.index">
    <div class="mx-auto max-w-5xl">
        <a href="{{ route('patients.show', [$patient, 'aba' => 'orcamentos']) }}#historico" class="text-sm text-slate-600 hover:underline">← Orçamentos de {{ $patient->name }}</a>

        @include('plans._details')
    </div>

    @can('approve', $plan)
        @include('plans._approval_dialog', ['plan' => $plan, 'autoshow' => $opensConversion])
    @endcan
</x-layouts.app>
