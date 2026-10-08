<x-layouts.app :title="'Remarcar · '.$patient->name">
    <div class="mx-auto max-w-3xl">
        <a href="{{ route('home', ['data' => App\Support\ClinicTime::format($appointment->scheduled_at, 'Y-m-d')]) }}" class="text-sm text-slate-600 hover:underline">← Início</a>
        <h1 class="mt-1 text-xl font-semibold tracking-tight">Remarcar agendamento</h1>

        <form method="POST" action="{{ route('appointments.update', $appointment) }}" class="mt-6 space-y-6" novalidate>
            @method('PUT')
            @include('appointments._form')
        </form>
    </div>
</x-layouts.app>
