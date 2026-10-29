@php
    use App\Support\ClinicTime;

    $total = $treatments->total();
    $filtered = array_filter(array_diff_key($filters, ['situacao' => true])) !== [] || $filters['situacao'] !== 'validos';
@endphp
<x-layouts.app title="Atendimentos">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold tracking-tight">Atendimentos</h1>
            <p class="mt-1 text-sm text-slate-600">{{ $total }} {{ $total === 1 ? 'registro' : 'registros' }}</p>
        </div>
        @if ($treatmentForm)
            <button type="button" class="btn btn-primary" data-dialog-open="{{ $treatmentForm['scope'] }}">Registrar atendimento</button>
        @endif
    </div>

    <form method="GET" action="{{ route('treatments.index') }}" class="mt-6 grid grid-cols-1 gap-2 sm:grid-cols-12" role="search">
        <div class="sm:col-span-3">
            <label for="q" class="sr-only">Buscar por paciente</label>
            <input id="q" name="q" type="search" value="{{ $filters['q'] }}" maxlength="100" class="input" placeholder="Paciente (nome ou CPF completo)">
        </div>
        <div class="sm:col-span-3">
            <label for="procedimento" class="sr-only">Procedimento</label>
            <select id="procedimento" name="procedimento" class="input">
                <option value="">Todos os procedimentos</option>
                @foreach ($procedures as $id => $name)
                    <option value="{{ $id }}" @selected($filters['procedimento'] === (string) $id)>{{ $name }}</option>
                @endforeach
            </select>
        </div>
        @include('stock._period_fields', ['span' => 'sm:col-span-2'])
        <div class="sm:col-span-3">
            <label for="profissional" class="sr-only">Profissional</label>
            <select id="profissional" name="profissional" class="input">
                <option value="">Todos os profissionais</option>
                @foreach ($professionals as $id => $name)
                    <option value="{{ $id }}" @selected($filters['profissional'] === (string) $id)>{{ $name }}</option>
                @endforeach
            </select>
        </div>
        <div class="sm:col-span-3">
            <label for="situacao" class="sr-only">Situação</label>
            <select id="situacao" name="situacao" class="input">
                @foreach (['validos' => 'Só válidos', 'anulados' => 'Só anulados', 'todos' => 'Válidos e anulados'] as $value => $label)
                    <option value="{{ $value }}" @selected($filters['situacao'] === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div class="flex gap-2 sm:col-span-6">
            <button type="submit" class="btn btn-secondary">Filtrar</button>
            @if ($filtered)
                <a href="{{ route('treatments.index') }}" class="btn btn-secondary">Limpar</a>
            @endif
        </div>
        @foreach (['q', 'procedimento', 'de', 'ate', 'profissional', 'situacao'] as $field)
            @error($field)
                <p class="text-sm text-red-700 sm:col-span-12">{{ $message }}</p>
            @enderror
        @endforeach
    </form>

    <div class="card mt-4 relative overflow-x-auto">
        <table class="table-stack w-full min-w-3xl text-left text-sm">
            <thead class="border-b border-slate-200 bg-slate-50 text-xs tracking-wide text-slate-500 uppercase">
                <tr>
                    <th scope="col" class="px-4 py-3 font-medium">Data</th>
                    <th scope="col" class="px-4 py-3 font-medium">Paciente</th>
                    <th scope="col" class="px-4 py-3 font-medium">Procedimento</th>
                    <th scope="col" class="px-4 py-3 font-medium">Profissional</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($treatments as $treatment)
                    {{-- Clique na linha abre o resumo em uma janela (app.js); o link do procedimento é o caminho pelo teclado. --}}
                    <tr class="cursor-pointer hover:bg-slate-50" data-summary="{{ route('patients.treatments.summary', [$treatment->patient, $treatment]) }}" data-summary-dialog="treatment-summary">
                        <td data-label="Data" class="px-4 py-3 whitespace-nowrap text-slate-600 tabular-nums">{{ ClinicTime::format($treatment->performed_at) }}</td>
                        <td data-label="Paciente" class="px-4 py-3">
                            <a href="{{ route('patients.show', $treatment->patient) }}" class="text-teal-800 hover:underline">{{ $treatment->patient->name }}</a>
                        </td>
                        <td data-label="Procedimento" class="px-4 py-3 font-medium">
                            <a href="{{ route('patients.treatments.show', [$treatment->patient, $treatment]) }}" class="text-teal-800 hover:underline" data-summary-link>{{ $treatment->procedure->name }}</a>
                            @if ($treatment->isVoided())
                                <span class="badge ml-1 bg-red-100 text-red-800">Anulado</span>
                            @endif
                            @if ($treatment->treated_areas)
                                <span class="block text-xs font-normal text-slate-500">{{ $treatment->treated_areas }}</span>
                            @endif
                        </td>
                        <td data-label="Profissional" class="px-4 py-3 text-slate-600">{{ $treatment->professional->name }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="px-4 py-10 text-center text-slate-500">
                            {{ $filtered ? 'Nenhum atendimento encontrado com estes filtros.' : 'Nenhum atendimento registrado ainda.' }}
                            @if ($treatmentForm && ! $filtered)
                                <button type="button" class="btn btn-primary mx-auto mt-4 flex w-fit" data-dialog-open="{{ $treatmentForm['scope'] }}">Registrar o primeiro atendimento</button>
                            @endif
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <p class="mt-3 text-xs text-slate-500">O histórico é permanente: um atendimento registrado não é alterado nem excluído. Informação nova entra como evolução.</p>

    <div class="mt-4">
        {{ $treatments->links() }}
    </div>

    <x-dialog id="treatment-summary" title="Atendimento" size="lg">
        <div data-summary-body data-summary-error="Não foi possível carregar o atendimento. Tente de novo ou abra a página completa." aria-live="polite"></div>
    </x-dialog>

    @if ($treatmentForm)
        @include('treatments._dialog', ['origin' => App\Enums\TreatmentOrigin::Treatments])
    @endif
</x-layouts.app>
