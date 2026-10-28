@php
    use App\Enums\PhotoStage;
    use App\Support\ClinicTime;
    use App\Support\LocalizedDecimal;

    $photosByStage = $treatment->photos->groupBy(fn ($photo) => $photo->stage->value);
    $entry = $treatment->relationLoaded('financialEntry') ? $treatment->financialEntry : null;
    $canAttach = auth()->user()->can('attach', $treatment);
    $canDetach = auth()->user()->can('detach', $treatment);
@endphp
<x-layouts.app :title="$treatment->procedure->name.' · '.$patient->name">
    <a href="{{ route('patients.show', $patient) }}" class="text-sm text-slate-600 hover:underline">← {{ $patient->name }}</a>
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="mt-1 text-xl font-semibold tracking-tight">
                {{ $treatment->procedure->name }}
                @if ($treatment->isVoided())
                    <span class="badge ml-1 bg-red-100 align-middle text-red-800">Anulado</span>
                @endif
            </h1>
            <p class="mt-1 text-sm text-slate-600">
                {{ ClinicTime::format($treatment->performed_at, 'd/m/Y') }} às {{ ClinicTime::format($treatment->performed_at, 'H:i') }}
                · {{ $treatment->professional->nameWithRegistration() }}
            </p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <a href="{{ route('patients.record', [$patient, 'atendimento' => $treatment->id]) }}" class="btn btn-secondary">Imprimir</a>
            @can('void', $treatment)
                <button type="button" class="btn btn-danger" data-dialog-open="treatment-void">Anular registro</button>
            @endcan
        </div>
    </div>

    @if ($treatment->isVoided())
        <div class="mt-6 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900" role="note">
            <p class="font-semibold">Registro anulado em {{ ClinicTime::format($treatment->voided_at) }} por {{ $treatment->voidedBy?->name ?? 'usuário removido' }}.</p>
            <p class="mt-1 whitespace-pre-line">Motivo: {{ $treatment->void_reason }}</p>
            <p class="mt-1">O estoque foi devolvido e a cobrança, excluída. O registro fica no prontuário só como histórico e não recebe fotos, documentos nem evoluções.</p>
            @can('create', App\Models\Treatment::class)
                {{-- Pelo agendamento reaberto, se houver; senão, registro avulso deste paciente. Nada é copiado: o erro pode ter sido o paciente. --}}
                <a href="{{ route('patients.treatments.create', $reopenedAppointment ? [$patient, 'agendamento' => $reopenedAppointment->id] : [$patient]) }}" class="btn btn-primary mt-3">Registrar o atendimento correto</a>
            @endcan
        </div>
    @endif

    <p class="mt-6 rounded-lg border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700" role="note">
        Registrado em {{ ClinicTime::format($treatment->created_at) }}{{ $treatment->creator ? ' por '.$treatment->creator->name : '' }}.
        O registro não pode mais ser alterado: novas informações entram como evolução. Se foi feito com erro (paciente, lote ou valor errado), ele pode ser anulado e registrado de novo.
    </p>

    <x-health-alerts :anamnesis="$patient->anamnesis" class="mt-6" />

    @include('treatments._clinical')

    @include('treatments._products')

    @include('treatments._finance')

    <section class="card mt-6 p-6" aria-labelledby="fotos">
        <h2 id="fotos" class="text-sm font-semibold tracking-wide text-slate-500 uppercase">Fotos do procedimento</h2>

        @if ($treatment->photos->isEmpty())
            <p class="mt-4 text-sm text-slate-600">Nenhuma foto adicionada.</p>
        @endif

        @foreach (PhotoStage::cases() as $stage)
            @continue(! $photosByStage->has($stage->value))

            <h3 class="mt-6 text-sm font-semibold">{{ $stage->label() }}</h3>
            <ul class="mt-3 grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
                @foreach ($photosByStage[$stage->value] as $photo)
                    @php $label = $stage->label().($photo->caption ? ' · '.$photo->caption : ''); @endphp
                    <li>
                        <a href="{{ route('patients.photos.show', [$patient, $photo]) }}" target="_blank" rel="noopener"
                           class="block overflow-hidden rounded-lg border border-slate-200 bg-slate-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-teal-700">
                            <img src="{{ route('patients.photos.show', [$patient, $photo, 'miniatura' => 1]) }}"
                                 alt="Foto: {{ $label }}" loading="lazy" width="{{ $photo->width }}" height="{{ $photo->height }}"
                                 class="aspect-square w-full object-cover">
                        </a>
                        <div class="mt-1.5 flex items-start justify-between gap-2">
                            <p class="min-w-0 text-xs break-words text-slate-600">{{ $photo->caption ?: 'Sem legenda' }}</p>
                            @if ($canDetach)
                                <form method="POST" action="{{ route('patients.photos.destroy', [$patient, $photo]) }}"
                                      data-confirm="Remover esta foto do atendimento?">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="cursor-pointer text-xs font-medium text-red-700 hover:underline">Remover</button>
                                </form>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        @endforeach

        @if ($canAttach)
            <x-photos.upload-form :action="route('patients.treatments.photos.store', [$patient, $treatment])" title="Adicionar fotos" />
        @endif
    </section>

    <section class="card mt-6 p-6" aria-labelledby="documentos">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 id="documentos" class="text-sm font-semibold tracking-wide text-slate-500 uppercase">Documentos</h2>
            @if ($canAttach)
                <button type="button" class="btn btn-secondary" data-dialog-open="document-upload">Adicionar documento</button>
            @endif
        </div>

        @if ($treatment->documents->isEmpty())
            <p class="mt-4 text-sm text-slate-600">Nenhum documento adicionado. Use para termos assinados, exames e receitas em PDF.</p>
        @else
            <ul class="mt-4 divide-y divide-slate-100">
                @foreach ($treatment->documents as $document)
                    <li class="flex flex-wrap items-center justify-between gap-3 py-3">
                        <div class="min-w-0">
                            <a href="{{ route('patients.treatments.documents.show', [$patient, $treatment, $document]) }}" class="font-medium break-words text-teal-800 hover:underline">{{ $document->caption }}</a>
                            <p class="mt-0.5 text-xs text-slate-500">
                                PDF · {{ number_format($document->size / 1024, 0, ',', '.') }} KB · adicionado em {{ ClinicTime::format($document->created_at) }}
                            </p>
                        </div>
                        @if ($canDetach)
                            <form method="POST" action="{{ route('patients.treatments.documents.destroy', [$patient, $treatment, $document]) }}"
                                  data-confirm="Remover o documento {{ $document->caption }}?">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="cursor-pointer text-xs font-medium text-red-700 hover:underline">Remover</button>
                            </form>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    <section class="card mt-6 p-6" aria-labelledby="evolucoes">
        <h2 id="evolucoes" class="text-sm font-semibold tracking-wide text-slate-500 uppercase">Evoluções e adendos</h2>

        @include('treatments._notes_list')

        @can('addNote', $treatment)
            <form method="POST" action="{{ route('patients.treatments.notes.store', [$patient, $treatment]) }}" class="mt-6 border-t border-slate-200 pt-6" novalidate data-keepalive="{{ route('session.keepalive') }}">
                @csrf
                <x-form.textarea name="body" label="Nova evolução" required rows="3" maxlength="5000"
                                 hint="Como o paciente evoluiu, correções ao registro ou informações do retorno. Depois de salva, não pode ser editada." />
                <div class="mt-4 flex justify-end">
                    <button type="submit" class="btn btn-primary">Registrar evolução</button>
                </div>
            </form>
        @endcan
    </section>

    @if ($canAttach)
        <x-dialog id="document-upload" title="Adicionar documento">
            <form method="POST" action="{{ route('patients.treatments.documents.store', [$patient, $treatment]) }}" enctype="multipart/form-data" class="space-y-4" novalidate>
                @csrf
                <input type="hidden" name="_dialog" value="document-upload">

                <x-form.input scope="document-upload" name="title" label="Título" required maxlength="150" autocomplete="off" placeholder="Ex.: Termo de consentimento assinado" />
                <div>
                    <label for="document" class="label">Arquivo PDF <span class="text-red-600" aria-hidden="true">*</span></label>
                    <input id="document" name="document" type="file" required accept="application/pdf"
                           @class(['input file:mr-3 file:cursor-pointer file:rounded-md file:border-0 file:bg-slate-100 file:px-3 file:py-1 file:text-sm file:font-medium', 'input-error' => old('_dialog') === 'document-upload' && $errors->has('document')])>
                    <p class="mt-1 text-xs text-slate-500">Até {{ App\Support\UploadLimits::megabytes() }} MB neste servidor. O arquivo é gravado com criptografia.</p>
                    @if (old('_dialog') === 'document-upload' && $errors->has('document'))
                        <p class="mt-1 text-sm text-red-700">{{ $errors->first('document') }}</p>
                    @endif
                </div>

                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" class="btn btn-secondary" data-dialog-close>Cancelar</button>
                    <button type="submit" class="btn btn-primary">Enviar documento</button>
                </div>
            </form>
        </x-dialog>
    @endif

    @can('void', $treatment)
        <x-dialog id="treatment-void" title="Anular registro">
            <form method="POST" action="{{ route('patients.treatments.void', [$patient, $treatment]) }}" class="space-y-4" novalidate
                  data-confirm="Anular este atendimento? O estoque será devolvido, a cobrança excluída e o agendamento reaberto.">
                @csrf
                <input type="hidden" name="_dialog" value="treatment-void">
                <p class="text-sm text-slate-600">
                    Use só para registro feito com erro: paciente, lote, quantidade ou valor errados. O registro continua no prontuário, marcado como anulado;
                    o estoque baixado volta, a cobrança é excluída e o agendamento volta para a agenda. Depois, registre o atendimento certo.
                </p>
                @if ($paidInstallments > 0)
                    <p class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900" role="note">
                        A cobrança deste atendimento tem {{ $paidInstallments === 1 ? 'uma parcela recebida' : $paidInstallments.' parcelas recebidas' }}.
                        Desfaça o pagamento no financeiro antes de anular.
                    </p>
                @else
                    <p class="text-sm text-slate-600">Se a cobrança já tiver pagamento registrado, a anulação é recusada.</p>
                @endif
                <x-form.textarea scope="treatment-void" name="reason" label="Motivo" required rows="3" maxlength="1000"
                                 hint="Ex.: registrado no paciente errado. Gravado com criptografia." />
                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" class="btn btn-secondary" data-dialog-close>Cancelar</button>
                    <button type="submit" class="btn btn-danger">Anular registro</button>
                </div>
            </form>
        </x-dialog>
    @endcan
</x-layouts.app>
