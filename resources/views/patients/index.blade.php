<x-layouts.app title="Pacientes">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold tracking-tight">{{ $archived ? 'Pacientes arquivados' : 'Pacientes' }}</h1>
            <p class="mt-1 text-sm text-slate-600">{{ $patients->total() }} {{ $patients->total() === 1 ? 'registro' : 'registros' }}</p>
        </div>
        @can('create', App\Models\Patient::class)
            <a href="{{ route('patients.create') }}" class="btn btn-primary">Novo paciente</a>
        @endcan
    </div>

    <form method="GET" action="{{ route('patients.index') }}" class="mt-6 flex flex-wrap items-start gap-2" role="search">
        @if ($archived)
            <input type="hidden" name="arquivados" value="1">
        @endif
        <div class="min-w-0 flex-1 basis-64">
            <label for="q" class="sr-only">Buscar por nome ou CPF</label>
            <input id="q" name="q" type="search" value="{{ $search }}" maxlength="100" class="input"
                   placeholder="Buscar por nome ou CPF completo">
            @error('q')
                <p class="mt-1 text-sm text-red-700">{{ $message }}</p>
            @enderror
        </div>
        <button type="submit" class="btn btn-secondary">Buscar</button>
        @if ($search !== '')
            <a href="{{ route('patients.index', $archived ? ['arquivados' => 1] : []) }}" class="btn btn-secondary">Limpar</a>
        @endif
    </form>

    <div class="card mt-4 relative overflow-x-auto">
        <table class="table-stack w-full min-w-3xl text-left text-sm">
            <thead class="border-b border-slate-200 bg-slate-50 text-xs tracking-wide text-slate-500 uppercase">
                <tr>
                    <th scope="col" class="px-4 py-3 font-medium">Nome</th>
                    <th scope="col" class="px-4 py-3 font-medium">CPF</th>
                    <th scope="col" class="px-4 py-3 font-medium">Nascimento</th>
                    <th scope="col" class="px-4 py-3 font-medium">Telefone</th>
                    <th scope="col" class="px-4 py-3"><span class="sr-only">Ações</span></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($patients as $patient)
                    <tr class="hover:bg-slate-50">
                        <td class="px-4 py-3 font-medium">
                            <div class="flex items-center gap-3">
                                <x-patients.avatar :patient="$patient" />
                                @if ($archived)
                                    {{ $patient->name }}
                                @else
                                    <a href="{{ route('patients.show', $patient) }}" class="text-teal-800 hover:underline">{{ $patient->name }}</a>
                                @endif
                            </div>
                        </td>
                        {{-- Na listagem o CPF aparece parcialmente oculto. --}}
                        <td data-label="CPF" class="px-4 py-3 whitespace-nowrap text-slate-600 tabular-nums">{{ $patient->maskedCpf() }}</td>
                        <td data-label="Nascimento" class="px-4 py-3 whitespace-nowrap text-slate-600">
                            {{ $patient->birth_date->format('d/m/Y') }}
                            <span class="text-slate-400">· {{ $patient->age() }} {{ $patient->age() === 1 ? 'ano' : 'anos' }}</span>
                        </td>
                        <td data-label="Telefone" class="px-4 py-3 whitespace-nowrap text-slate-600 tabular-nums">{{ $patient->formattedPhone() ?: '—' }}</td>
                        <td class="px-4 py-3 text-right whitespace-nowrap">
                            @if ($archived)
                                @can('restore', $patient)
                                    <form method="POST" action="{{ route('patients.restore', $patient) }}" class="inline"
                                          data-confirm="Restaurar o cadastro de {{ $patient->name }}?">
                                        @csrf
                                        @method('PATCH')
                                        <button type="submit" class="font-medium text-teal-800 hover:underline">Restaurar</button>
                                    </form>
                                @endcan
                            @else
                                <a href="{{ route('patients.edit', $patient) }}" class="font-medium text-teal-800 hover:underline">Editar</a>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-10 text-center text-slate-500">
                            {{ $search !== '' ? 'Nenhum paciente encontrado para esta busca.' : 'Nenhum paciente por aqui ainda.' }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $patients->links() }}
    </div>

    @can('viewArchived', App\Models\Patient::class)
        <p class="mt-6 text-sm">
            @if ($archived)
                <a href="{{ route('patients.index') }}" class="text-teal-800 hover:underline">← Voltar para pacientes ativos</a>
            @else
                <a href="{{ route('patients.index', ['arquivados' => 1]) }}" class="text-slate-600 hover:underline">Ver pacientes arquivados</a>
            @endif
        </p>
    @endcan
</x-layouts.app>
