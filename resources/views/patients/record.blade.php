@php
    use App\Support\ClinicTime;
    use App\Support\LocalizedDecimal;

    $anamnesis = $patient->anamnesis;
    $address = collect([
        trim(($patient->street ?? '').($patient->number ? ', '.$patient->number : '')),
        $patient->complement,
        $patient->district,
        trim(($patient->city ?? '').($patient->state ? ' / '.$patient->state : ''), ' /'),
        $patient->formattedZipCode() ? 'CEP '.$patient->formattedZipCode() : null,
    ])->filter()->implode(' · ');
    $back = match (true) {
        $patient->trashed() => route('patients.index', ['arquivados' => 1]),
        $single => route('patients.treatments.show', [$patient, $treatments->first()]),
        default => route('patients.show', $patient),
    };
    $heading = 'text-xs font-semibold tracking-wide text-slate-500 uppercase';
@endphp
<x-layouts.print :title="'Prontuário · '.$patient->name" :back="$back">
    <header class="border-b border-slate-300 pb-4">
        <p class="text-xs text-slate-500">{{ config('app.name') }} · {{ $single ? 'Registro de atendimento' : 'Prontuário' }}</p>
        <h1 class="mt-1 text-xl font-semibold">{{ $patient->name }}@if ($patient->trashed()) <span class="text-sm font-normal text-slate-500">(cadastro arquivado)</span>@endif</h1>
        <p class="mt-1 text-slate-600">
            CPF {{ $patient->formattedCpf() }}
            · nascimento {{ $patient->birth_date->format('d/m/Y') }} ({{ $patient->age() }} anos)
            @if ($patient->sex) · {{ $patient->sex->label() }} @endif
        </p>
        <p class="mt-1 text-slate-600">
            {{ $patient->formattedPhone() ?: 'Sem telefone' }} · {{ $patient->email ?: 'sem e-mail' }}
            @if ($address) · {{ $address }} @endif
        </p>
    </header>

    <section class="mt-5 break-inside-avoid">
        <h2 class="{{ $heading }}">Ficha de saúde</h2>
        @if ($anamnesis)
            <dl class="mt-2 grid grid-cols-2 gap-3">
                @foreach ([
                    'Alergias' => $anamnesis->allergies ?: 'Nenhuma informada',
                    'Gestante ou lactante' => $anamnesis->pregnant_or_lactating ? 'Sim' : 'Não',
                    'Medicamentos em uso' => $anamnesis->medications,
                    'Condições de saúde' => $anamnesis->health_conditions,
                    'Procedimentos estéticos anteriores' => $anamnesis->previous_procedures,
                    'Observações' => $anamnesis->notes,
                ] as $label => $value)
                    <div>
                        <dt class="text-xs text-slate-500">{{ $label }}</dt>
                        <dd class="whitespace-pre-line">{{ $value ?: '—' }}</dd>
                    </div>
                @endforeach
            </dl>
            <p class="mt-2 text-xs text-slate-500">Atualizada em {{ ClinicTime::format($anamnesis->updated_at) }}.</p>
        @else
            <p class="mt-2 text-slate-600">Não preenchida.</p>
        @endif
    </section>

    <h2 class="{{ $heading }} mt-6">{{ $single ? 'Atendimento' : 'Atendimentos ('.$treatments->count().')' }}</h2>
    @forelse ($treatments as $treatment)
        <article class="mt-3 break-inside-avoid rounded-lg border border-slate-300 p-4">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h3 class="text-base font-semibold">{{ $treatment->procedure->name }}</h3>
                <p class="text-slate-600">{{ ClinicTime::format($treatment->performed_at) }} · {{ $treatment->professional->name }}</p>
            </div>
            @if ($treatment->isVoided())
                <p class="mt-2 rounded border border-red-300 bg-red-50 px-3 py-2 text-red-900">
                    Anulado em {{ ClinicTime::format($treatment->voided_at) }} por {{ $treatment->voidedBy?->name ?? 'usuário removido' }}.
                    Motivo: {{ $treatment->void_reason }}
                </p>
            @endif
            <dl class="mt-3 space-y-2">
                @foreach ([
                    'Áreas tratadas' => $treatment->treated_areas,
                    'Avaliação' => $treatment->assessment,
                    'Descrição' => $treatment->description,
                    'Orientações' => $treatment->post_care,
                    'Intercorrências' => $treatment->complications,
                    'Retorno previsto' => $treatment->return_date?->format('d/m/Y'),
                ] as $label => $value)
                    @if ($value)
                        <div>
                            <dt class="text-xs text-slate-500">{{ $label }}</dt>
                            <dd class="whitespace-pre-line">{{ $value }}</dd>
                        </div>
                    @endif
                @endforeach
            </dl>
            @if ($treatment->products->isNotEmpty())
                <table class="table-stack mt-3 w-full text-left">
                    <thead class="text-xs text-slate-500">
                        <tr>
                            <th scope="col" class="py-1 font-medium">Produto</th>
                            <th scope="col" class="py-1 font-medium">Lote</th>
                            <th scope="col" class="py-1 font-medium">Validade</th>
                            <th scope="col" class="py-1 text-right font-medium">Quantidade</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200">
                        @foreach ($treatment->products as $used)
                            <tr>
                                <td class="py-1">{{ $used->batch->product->name }}</td>
                                <td data-label="Lote" class="py-1 tabular-nums">{{ $used->batch->batch_code }}</td>
                                <td data-label="Validade" class="py-1 tabular-nums">{{ $used->batch->expires_on?->format('d/m/Y') ?? '—' }}</td>
                                <td data-label="Quantidade" class="py-1 text-right tabular-nums">{{ LocalizedDecimal::quantity($used->quantity) }} {{ $used->batch->product->unit->symbol() }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
            @if ($treatment->notes->isNotEmpty())
                <h4 class="mt-3 text-xs text-slate-500">Evoluções</h4>
                <ol class="mt-1 space-y-1">
                    @foreach ($treatment->notes as $note)
                        <li>
                            <span class="text-xs text-slate-500">{{ ClinicTime::format($note->created_at) }} · {{ $note->author?->name ?? 'Usuário removido' }}:</span>
                            <span class="whitespace-pre-line">{{ $note->body }}</span>
                        </li>
                    @endforeach
                </ol>
            @endif
        </article>
    @empty
        <p class="mt-2 text-slate-600">Nenhum atendimento registrado.</p>
    @endforelse

    <footer class="mt-8 border-t border-slate-300 pt-3 text-xs text-slate-500">
        Emitido em {{ ClinicTime::format(now()) }} por {{ $printedBy->name }}. Documento com dados pessoais e de saúde: guarde e entregue somente ao paciente ou a quem ele autorizar.
    </footer>
</x-layouts.print>
