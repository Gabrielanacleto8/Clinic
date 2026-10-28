@php
    use App\Support\ClinicTime;
    use App\Support\LocalizedDecimal;
@endphp
<x-layouts.app title="Orçamentos">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold tracking-tight">Orçamentos e pacotes</h1>
            <p class="mt-1 text-sm text-slate-600">{{ $plans->total() }} {{ $plans->total() === 1 ? 'registro' : 'registros' }}. Para criar um orçamento, abra a ficha do paciente.</p>
        </div>
        @can('viewAny', App\Models\Patient::class)
            <a href="{{ route('patients.index') }}" class="btn btn-primary">Escolher paciente</a>
        @endcan
    </div>

    <form method="GET" action="{{ route('plans.index') }}" class="mt-6 grid grid-cols-1 gap-2 sm:grid-cols-12" role="search">
        <div class="sm:col-span-6">
            <label for="q" class="sr-only">Paciente</label>
            <input id="q" name="q" type="search" value="{{ $filters['q'] }}" maxlength="100" class="input" placeholder="Paciente (nome, CPF completo ou telefone)">
        </div>
        <div class="sm:col-span-4">
            <label for="situacao" class="sr-only">Situação</label>
            <select id="situacao" name="situacao" class="input">
                @foreach ($statusOptions as $value => $label)
                    <option value="{{ $value }}" @selected($filters['situacao'] === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <button type="submit" class="btn btn-secondary sm:col-span-2">Filtrar</button>
    </form>

    <div class="card mt-4 relative overflow-x-auto">
        <table class="table-stack w-full min-w-3xl text-left text-sm">
            <thead class="border-b border-slate-200 bg-slate-50 text-xs tracking-wide text-slate-500 uppercase">
                <tr>
                    <th scope="col" class="px-4 py-3 font-medium">Nº</th>
                    <th scope="col" class="px-4 py-3 font-medium">Paciente</th>
                    <th scope="col" class="px-4 py-3 font-medium">Procedimentos</th>
                    <th scope="col" class="px-4 py-3 text-right font-medium">Total</th>
                    <th scope="col" class="px-4 py-3 font-medium">Situação</th>
                    <th scope="col" class="px-4 py-3 text-right font-medium">Sessões restantes</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($plans as $plan)
                    <tr class="hover:bg-slate-50">
                        <td class="px-4 py-3 tabular-nums">
                            <a href="{{ route('patients.plans.show', [$plan->patient, $plan]) }}" class="font-medium text-teal-800 hover:underline">nº {{ $plan->id }}</a>
                            <span class="block text-xs text-slate-500">{{ ClinicTime::format($plan->created_at, 'd/m/Y') }}</span>
                        </td>
                        <td data-label="Paciente" class="px-4 py-3">{{ $plan->patient->name }}</td>
                        <td data-label="Procedimentos" class="px-4 py-3 text-slate-600">{{ $plan->items->map(fn ($item) => $item->procedure->name.' ('.$item->sessions.')')->implode(', ') }}</td>
                        <td data-label="Total" class="px-4 py-3 text-right tabular-nums">{{ LocalizedDecimal::money($plan->total()) }}</td>
                        <td data-label="Situação" class="px-4 py-3">
                            <span class="badge {{ $plan->status->badgeClasses() }}">{{ $plan->status->label() }}</span>
                            @if ($plan->isExpired())
                                <span class="badge bg-red-100 text-red-800">Vencido</span>
                            @endif
                        </td>
                        <td data-label="Sessões restantes" class="px-4 py-3 text-right tabular-nums">{{ $plan->isApproved() ? $plan->remainingSessions() : '—' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-10 text-center text-slate-500">
                            Nenhum orçamento encontrado.
                            <span class="mt-1 block text-xs">Crie o primeiro pela ficha do paciente, em Mais › Novo orçamento.</span>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $plans->links() }}
    </div>
</x-layouts.app>
