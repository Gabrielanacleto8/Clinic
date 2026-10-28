<x-layouts.app :title="'Novo orçamento · '.$patient->name" section="plans.index">
    <div class="mx-auto max-w-3xl">
        <a href="{{ route('patients.show', $patient) }}" class="text-sm text-slate-600 hover:underline">← {{ $patient->name }}</a>
        <h1 class="mt-1 text-xl font-semibold tracking-tight">Novo orçamento</h1>

        <form method="POST" action="{{ route('patients.plans.store', $patient) }}" class="mt-6 space-y-6" novalidate data-keepalive="{{ route('session.keepalive') }}">
            @include('plans._form')
        </form>
    </div>
</x-layouts.app>
