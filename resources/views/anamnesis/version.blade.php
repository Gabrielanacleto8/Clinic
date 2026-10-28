@php use App\Support\ClinicTime; @endphp
<x-layouts.app :title="'Versão anterior da ficha · '.$patient->name">
    <div class="mx-auto max-w-3xl space-y-6">
        <div>
            <a href="{{ route('patients.show', $patient) }}" class="text-sm text-slate-600 hover:underline">← {{ $patient->name }}</a>
            <h1 class="mt-1 text-xl font-semibold tracking-tight">Ficha de saúde: versão anterior</h1>
            <p class="mt-1 text-sm text-slate-600">
                Como a ficha estava até {{ ClinicTime::format($version->created_at) }}.
                {{ $version->recorded_at ? 'Gravada em '.ClinicTime::format($version->recorded_at) : 'Gravada antes do histórico' }}
                por {{ $version->author?->name ?? 'usuário removido' }}. Só leitura.
            </p>
        </div>

        <section class="card p-6">
            <dl class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                @foreach ([
                    'Alergias' => $version->field('allergies') ?: 'Nenhuma informada',
                    'Gestante ou lactante' => $version->field('pregnant_or_lactating') ? 'Sim' : 'Não',
                    'Medicamentos em uso' => $version->field('medications'),
                    'Condições de saúde' => $version->field('health_conditions'),
                    'Procedimentos estéticos anteriores' => $version->field('previous_procedures'),
                    'Observações' => $version->field('notes'),
                ] as $label => $value)
                    <div>
                        <dt class="text-xs text-slate-500">{{ $label }}</dt>
                        <dd class="text-sm whitespace-pre-line">{{ $value ?: '—' }}</dd>
                    </div>
                @endforeach
            </dl>
        </section>

        @include('anamnesis._versions', ['current' => $version->id])
    </div>
</x-layouts.app>
