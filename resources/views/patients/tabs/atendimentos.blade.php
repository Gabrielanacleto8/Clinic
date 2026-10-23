<section class="mt-4" aria-labelledby="atendimentos">
    {{-- A ficha só consulta: atendimento se registra pela agenda ou pela lista de atendimentos. --}}
    <h2 id="atendimentos" class="text-lg font-semibold tracking-tight">Atendimentos</h2>
    <p class="mt-0.5 text-sm text-slate-600">{{ $records->total() }} {{ $records->total() === 1 ? 'registro' : 'registros' }}</p>

    <div class="card mt-4 relative overflow-x-auto">
        <table class="table-stack w-full min-w-2xl text-left text-sm">
            <thead class="border-b border-slate-200 bg-slate-50 text-xs tracking-wide text-slate-500 uppercase">
                <tr>
                    <th scope="col" class="px-4 py-3 font-medium">Data</th>
                    <th scope="col" class="px-4 py-3 font-medium">Procedimento</th>
                    <th scope="col" class="px-4 py-3 font-medium">Profissional</th>
                    <th scope="col" class="px-4 py-3 font-medium">Fotos</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($records as $treatment)
                    <tr class="hover:bg-slate-50">
                        <td data-label="Data" class="px-4 py-3 whitespace-nowrap text-slate-600 tabular-nums">{{ App\Support\ClinicTime::format($treatment->performed_at) }}</td>
                        <td data-label="Procedimento" class="px-4 py-3 font-medium">
                            <a href="{{ route('patients.treatments.show', [$patient, $treatment]) }}" class="text-teal-800 hover:underline">{{ $treatment->procedure->name }}</a>
                            @if ($treatment->isVoided())
                                <span class="badge ml-1 bg-red-100 text-red-800">Anulado</span>
                            @endif
                            @if ($treatment->treated_areas)
                                <span class="block text-xs font-normal text-slate-500">{{ $treatment->treated_areas }}</span>
                            @endif
                        </td>
                        <td data-label="Profissional" class="px-4 py-3 text-slate-600">{{ $treatment->professional->name }}</td>
                        <td data-label="Fotos" class="px-4 py-3 text-slate-600 tabular-nums">{{ $treatment->photos_count ?: '—' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="px-4 py-10 text-center text-slate-500">Nenhum atendimento registrado para este paciente.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $records->links() }}
    </div>
</section>
