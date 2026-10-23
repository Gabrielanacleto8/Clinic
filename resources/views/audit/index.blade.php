@php
    use App\Support\AuditDescription;
    use App\Support\ClinicTime;

    $total = $logs->total();
    $filtered = array_filter($filters) !== [];
@endphp
<x-layouts.app title="Auditoria">
    <div>
        <h1 class="text-xl font-semibold tracking-tight">Auditoria</h1>
        <p class="mt-1 text-sm text-slate-600">
            @if ($patient)
                Tudo o que foi feito no cadastro, prontuário, agenda e financeiro de <span class="font-medium">{{ $patient->name }}</span>.
            @else
                Quem fez o quê, quando e de onde. Os registros não podem ser alterados nem apagados.
            @endif
        </p>
        <p class="mt-1 text-sm text-slate-600">{{ $total }} {{ $total === 1 ? 'registro' : 'registros' }}</p>
    </div>

    <form method="GET" action="{{ route('audit.index') }}" class="mt-6 grid grid-cols-1 gap-2 sm:grid-cols-12" role="search">
        @if ($filters['paciente'] !== '')
            <input type="hidden" name="paciente" value="{{ $filters['paciente'] }}">
        @endif
        <div class="sm:col-span-3">
            <label for="usuario" class="sr-only">Usuário</label>
            <select id="usuario" name="usuario" class="input">
                <option value="">Todos os usuários</option>
                @foreach ($users as $id => $name)
                    <option value="{{ $id }}" @selected($filters['usuario'] === (string) $id)>{{ $name }}</option>
                @endforeach
            </select>
        </div>
        <div class="sm:col-span-3">
            <label for="acao" class="sr-only">Ação</label>
            <select id="acao" name="acao" class="input">
                <option value="">Todas as ações</option>
                @foreach ($actions as $value => $label)
                    <option value="{{ $value }}" @selected($filters['acao'] === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        @include('stock._period_fields', ['span' => 'sm:col-span-2'])
        <button type="submit" class="btn btn-secondary sm:col-span-2">Filtrar</button>
        @foreach (['usuario', 'acao', 'paciente', 'de', 'ate'] as $field)
            @error($field)
                <p class="text-sm text-red-700 sm:col-span-12">{{ $message }}</p>
            @enderror
        @endforeach
    </form>

    @if ($patient)
        <p class="mt-3 text-sm">
            <a href="{{ route('audit.index', array_filter([...$filters, 'paciente' => null])) }}" class="text-teal-800 hover:underline">Ver a auditoria de todos os registros</a>
        </p>
    @endif

    <div class="card mt-4 relative overflow-x-auto">
        <table class="table-stack w-full min-w-4xl text-left text-sm">
            <thead class="border-b border-slate-200 bg-slate-50 text-xs tracking-wide text-slate-500 uppercase">
                <tr>
                    <th scope="col" class="px-4 py-3 font-medium">Quando</th>
                    <th scope="col" class="px-4 py-3 font-medium">Usuário</th>
                    <th scope="col" class="px-4 py-3 font-medium">Ação</th>
                    <th scope="col" class="px-4 py-3 font-medium">Registro</th>
                    <th scope="col" class="px-4 py-3 font-medium">Detalhes</th>
                    <th scope="col" class="px-4 py-3 font-medium">Origem</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($logs as $log)
                    <tr>
                        <td class="px-4 py-3 whitespace-nowrap text-slate-600 tabular-nums">{{ ClinicTime::format($log->created_at, 'd/m/Y H:i:s') }}</td>
                        <td data-label="Usuário" class="px-4 py-3">{{ $log->user?->name ?? '—' }}</td>
                        <td data-label="Ação" class="px-4 py-3 font-medium">{{ $log->action->label() }}</td>
                        <td data-label="Registro" class="px-4 py-3 whitespace-nowrap text-slate-600">
                            {{ AuditDescription::subject($log) }}
                        </td>
                        {{-- Na auditoria entram só nomes de campos e identificadores: é seguro listar. --}}
                        <td data-label="Detalhes" class="px-4 py-3 text-xs text-slate-600">
                            @foreach (AuditDescription::details($log->metadata) as $label => $text)
                                <span class="block"><span class="text-slate-500">{{ $label }}:</span> {{ $text }}</span>
                            @endforeach
                            @if (isset($log->metadata['patient_id']) && ! $patient)
                                <a href="{{ route('audit.index', ['paciente' => $log->metadata['patient_id']]) }}" class="mt-0.5 block text-teal-800 hover:underline">Ver tudo deste paciente</a>
                            @endif
                        </td>
                        <td data-label="Origem" class="px-4 py-3 text-xs text-slate-500">
                            <span class="block tabular-nums">{{ $log->ip_address ?? '—' }}</span>
                            <span class="block max-w-56 truncate" title="{{ $log->user_agent }}">{{ $log->user_agent }}</span>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-10 text-center text-slate-500">
                            {{ $filtered ? 'Nenhum registro encontrado com estes filtros.' : 'Nenhum registro de auditoria ainda.' }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $logs->links() }}
    </div>
</x-layouts.app>
