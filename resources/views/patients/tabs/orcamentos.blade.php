@php
    use App\Support\ClinicTime;
    use App\Support\LocalizedDecimal;
@endphp
<section class="mt-4" aria-labelledby="orcamentos-historico">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h2 id="orcamentos-historico" class="text-lg font-semibold tracking-tight">Orçamentos e pacotes</h2>
            <p class="mt-0.5 text-sm text-slate-600">{{ $records->total() }} {{ $records->total() === 1 ? 'registro' : 'registros' }}</p>
        </div>
        @can('create', App\Models\TreatmentPlan::class)
            <a href="{{ route('plans.create', ['paciente' => $patient->id]) }}" class="btn btn-primary">Novo orçamento</a>
        @endcan
    </div>

    <div class="card mt-4 relative overflow-x-auto">
        <table class="table-stack w-full min-w-2xl text-left text-sm">
            <thead class="border-b border-slate-200 bg-slate-50 text-xs tracking-wide text-slate-500 uppercase">
                <tr>
                    <th scope="col" class="px-4 py-3 font-medium">Nº</th>
                    <th scope="col" class="px-4 py-3 font-medium">Procedimentos</th>
                    <th scope="col" class="px-4 py-3 text-right font-medium">Total</th>
                    <th scope="col" class="px-4 py-3 font-medium">Situação</th>
                    <th scope="col" class="px-4 py-3 text-right font-medium">Sessões restantes</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($records as $plan)
                    <tr class="hover:bg-slate-50">
                        <td class="px-4 py-3 tabular-nums">
                            <a href="{{ route('patients.plans.show', [$patient, $plan]) }}" class="font-medium text-slate-800 hover:underline">nº {{ $plan->id }}</a>
                            <span class="block text-xs text-slate-500">{{ ClinicTime::format($plan->created_at, 'd/m/Y') }}</span>
                        </td>
                        <td data-label="Procedimentos" class="px-4 py-3 text-slate-600">{{ $plan->items->map(fn ($item) => $item->procedure->name.' ('.$item->sessions.')')->implode(', ') }}</td>
                        <td data-label="Total" class="px-4 py-3 text-right tabular-nums">{{ LocalizedDecimal::money($plan->total()) }}</td>
                        <td data-label="Situação" class="px-4 py-3"><span class="badge {{ $plan->status->badgeClasses() }}">{{ $plan->status->label() }}</span></td>
                        <td data-label="Sessões restantes" class="px-4 py-3 text-right tabular-nums">{{ $plan->isApproved() ? $plan->remainingSessions() : '—' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-10 text-center text-slate-500">Nenhum orçamento para este paciente.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $records->links() }}
    </div>
</section>
