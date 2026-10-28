<x-layouts.app :title="'Orçamento nº '.$plan->id.' · '.$patient->name" section="plans.index">
    <div class="mx-auto max-w-3xl">
        <a href="{{ route('patients.plans.show', [$patient, $plan]) }}" class="text-sm text-slate-600 hover:underline">← Orçamento nº {{ $plan->id }}</a>
        <h1 class="mt-1 text-xl font-semibold tracking-tight">Editar orçamento</h1>
        <p class="mt-1 text-sm text-slate-600">{{ $patient->name }}</p>

        <form method="POST" action="{{ route('patients.plans.update', [$patient, $plan]) }}" class="mt-6 space-y-6" novalidate data-keepalive="{{ route('session.keepalive') }}">
            @method('PUT')
            @include('plans._form')
        </form>
    </div>
</x-layouts.app>
