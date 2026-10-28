<x-layouts.app :title="'Termo de consentimento · '.$patient->name">
    <div class="mx-auto max-w-xl">
        <a href="{{ route('patients.show', $patient) }}" class="text-sm text-slate-600 hover:underline">← {{ $patient->name }}</a>
        <h1 class="mt-1 text-xl font-semibold tracking-tight">Termo de consentimento</h1>
        <p class="mt-1 text-sm text-slate-600">Escolha o procedimento. O termo abre pronto para imprimir, com os dados do paciente; depois de assinado, envie-o como documento do atendimento.</p>

        @if ($procedures->isEmpty())
            <p class="card mt-6 p-6 text-sm text-slate-600">Nenhum procedimento ativo no catálogo.</p>
        @else
            <ul class="card mt-6 divide-y divide-slate-100">
                @foreach ($procedures as $procedure)
                    <li>
                        <a href="{{ route('patients.consent-term', [$patient, 'procedimento' => $procedure->id]) }}" class="flex items-center justify-between gap-3 px-4 py-3 hover:bg-slate-50 sm:px-6">
                            <span class="font-medium text-teal-800">{{ $procedure->name }}</span>
                            <span class="text-xs text-slate-500">{{ filled($procedure->consent_template) ? 'Texto próprio' : 'Modelo padrão' }}</span>
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</x-layouts.app>
