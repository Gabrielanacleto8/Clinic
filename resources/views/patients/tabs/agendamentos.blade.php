@php use App\Support\ClinicTime; @endphp
<section class="mt-4" aria-labelledby="agendamentos-historico">
    <h2 id="agendamentos-historico" class="text-lg font-semibold tracking-tight">Agendamentos</h2>
    <p class="mt-0.5 text-sm text-slate-600">{{ $records->total() }} {{ $records->total() === 1 ? 'registro' : 'registros' }}</p>

    <div class="card mt-4 relative overflow-x-auto">
        <table class="table-stack w-full min-w-2xl text-left text-sm">
            <thead class="border-b border-slate-200 bg-slate-50 text-xs tracking-wide text-slate-500 uppercase">
                <tr>
                    <th scope="col" class="px-4 py-3 font-medium">Data</th>
                    <th scope="col" class="px-4 py-3 font-medium">Procedimento</th>
                    <th scope="col" class="px-4 py-3 font-medium">Profissional</th>
                    <th scope="col" class="px-4 py-3 font-medium">Situação</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($records as $appointment)
                    <tr class="hover:bg-slate-50">
                        <td data-label="Data" class="px-4 py-3 whitespace-nowrap tabular-nums">
                            @can('viewAny', App\Models\Appointment::class)
                                <a href="{{ route('agenda.index', ['data' => ClinicTime::format($appointment->scheduled_at, 'Y-m-d')]) }}" class="text-slate-800 hover:underline">{{ ClinicTime::format($appointment->scheduled_at) }}</a>
                            @else
                                {{ ClinicTime::format($appointment->scheduled_at) }}
                            @endcan
                        </td>
                        <td data-label="Procedimento" class="px-4 py-3">{{ $appointment->procedure?->name ?? '—' }}</td>
                        <td data-label="Profissional" class="px-4 py-3 text-slate-600">{{ $appointment->professional->name }}</td>
                        <td data-label="Situação" class="px-4 py-3"><span class="badge {{ $appointment->status->badgeClasses() }}">{{ $appointment->status->label() }}</span></td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="px-4 py-10 text-center text-slate-500">Nenhum agendamento para este paciente.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $records->links() }}
    </div>
</section>
