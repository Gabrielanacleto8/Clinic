@php
    use App\Enums\PhotoStage;
    use App\Support\ClinicTime;
    use App\Support\LocalizedDecimal;

    $photosByStage = $treatment->photos->groupBy(fn ($photo) => $photo->stage->value);
    $maxPhotos = config('clinic.photos.max_per_upload');
    $entry = $treatment->relationLoaded('financialEntry') ? $treatment->financialEntry : null;
    $canAttach = auth()->user()->can('attach', $treatment);
    $canDetach = auth()->user()->can('detach', $treatment);
@endphp
<x-layouts.app :title="$treatment->procedure->name.' · '.$patient->name">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <a href="{{ route('patients.show', $patient) }}" class="text-sm text-slate-600 hover:underline">← {{ $patient->name }}</a>
            <h1 class="mt-1 flex flex-wrap items-center gap-2 text-xl font-semibold tracking-tight">
                {{ $treatment->procedure->name }}
                <span class="badge {{ $treatment->status->badgeClasses() }}">{{ $treatment->status->label() }}</span>
            </h1>
            <p class="mt-1 text-sm text-slate-600">
                {{ ClinicTime::format($treatment->performed_at, 'd/m/Y') }} às {{ ClinicTime::format($treatment->performed_at, 'H:i') }}
                · {{ $treatment->professional->name }}
            </p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            @can('delete', $treatment)
                <form method="POST" action="{{ route('patients.treatments.destroy', [$patient, $treatment]) }}"
                      data-confirm="Descartar este atendimento em andamento? Ele sai do prontuário e nada é baixado do estoque.">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn btn-danger">Descartar</button>
                </form>
            @endcan
            @can('update', $treatment)
                <a href="{{ route('patients.treatments.edit', [$patient, $treatment]) }}" class="btn btn-secondary">Editar</a>
            @endcan
            @can('finalize', $treatment)
                <a href="{{ route('patients.treatments.finalization.create', [$patient, $treatment]) }}" class="btn btn-primary">Finalizar atendimento</a>
            @endcan
        </div>
    </div>

    @if ($treatment->isDraft())
        <p class="mt-6 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900" role="note">
            Atendimento em andamento. O estoque e o financeiro só são atualizados quando ele for finalizado.
        </p>
    @else
        <p class="mt-6 rounded-lg border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700" role="note">
            Finalizado em {{ ClinicTime::format($treatment->finalized_at) }}. O registro não pode mais ser alterado: novas informações entram como evolução.
        </p>
    @endif

    <x-health-alerts :anamnesis="$patient->anamnesis" class="mt-6" />

    <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-3">
        <section class="card p-6 lg:col-span-2">
            <h2 class="text-sm font-semibold tracking-wide text-slate-500 uppercase">Procedimento</h2>
            <dl class="mt-4 space-y-4">
                <div>
                    <dt class="text-xs text-slate-500">Áreas tratadas</dt>
                    <dd class="mt-0.5 text-sm">{{ $treatment->treated_areas ?: '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-slate-500">Avaliação</dt>
                    <dd class="mt-0.5 text-sm whitespace-pre-line">{{ $treatment->assessment ?: '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-slate-500">Descrição</dt>
                    <dd class="mt-0.5 text-sm whitespace-pre-line">{{ $treatment->description }}</dd>
                </div>
            </dl>
        </section>

        <section class="card p-6">
            <h2 class="text-sm font-semibold tracking-wide text-slate-500 uppercase">Pós-procedimento</h2>
            <dl class="mt-4 space-y-4">
                <div>
                    <dt class="text-xs text-slate-500">Orientações</dt>
                    <dd class="mt-0.5 text-sm whitespace-pre-line">{{ $treatment->post_care ?: '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-slate-500">Intercorrências</dt>
                    <dd class="mt-0.5 text-sm whitespace-pre-line">{{ $treatment->complications ?: 'Nenhuma registrada' }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-slate-500">Retorno previsto</dt>
                    <dd class="mt-0.5 text-sm">{{ $treatment->return_date?->format('d/m/Y') ?? '—' }}</dd>
                </div>
            </dl>
        </section>
    </div>

    <section class="card mt-6 relative overflow-x-auto">
        <h2 class="px-6 pt-6 text-sm font-semibold tracking-wide text-slate-500 uppercase">
            Produtos {{ $treatment->isFinalized() ? 'utilizados' : 'a utilizar' }}
        </h2>
        @if ($treatment->products->isEmpty())
            <p class="px-6 pt-4 pb-6 text-sm text-slate-600">Nenhum produto registrado.</p>
        @else
            <table class="table-stack mt-4 w-full min-w-lg text-left text-sm">
                <thead class="border-y border-slate-200 bg-slate-50 text-xs tracking-wide text-slate-500 uppercase">
                    <tr>
                        <th scope="col" class="px-6 py-3 font-medium">Produto</th>
                        <th scope="col" class="px-4 py-3 font-medium">Lote</th>
                        <th scope="col" class="px-4 py-3 font-medium">Validade</th>
                        <th scope="col" class="px-4 py-3 text-right font-medium">Quantidade</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($treatment->products as $used)
                        <tr>
                            <td class="px-6 py-3 font-medium">{{ $used->batch->product->name }}</td>
                            <td data-label="Lote" class="px-4 py-3 text-slate-600 tabular-nums">{{ $used->batch->batch_code }}</td>
                            <td data-label="Validade" class="px-4 py-3 text-slate-600 tabular-nums">{{ $used->batch->expires_on?->format('d/m/Y') ?? '—' }}</td>
                            <td data-label="Quantidade" class="px-4 py-3 text-right whitespace-nowrap tabular-nums">
                                {{ LocalizedDecimal::quantity($used->quantity) }} {{ $used->batch->product->unit->symbol() }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </section>

    @if ($entry)
        <section class="card mt-6 relative overflow-x-auto" aria-labelledby="financeiro">
            <h2 id="financeiro" class="px-6 pt-6 text-sm font-semibold tracking-wide text-slate-500 uppercase">Financeiro</h2>
            <p class="px-6 pt-1 text-sm text-slate-600">
                {{ LocalizedDecimal::money($entry->installments->sum('amount')) }} · {{ $entry->payment_method->label() }}
            </p>
            <table class="table-stack mt-4 w-full min-w-md text-left text-sm">
                <tbody class="divide-y divide-slate-100 border-t border-slate-200">
                    @foreach ($entry->installments as $installment)
                        @php $badge = $installment->statusBadge(); @endphp
                        <tr>
                            <td data-label="Parcela" class="px-6 py-3 text-slate-600">{{ $entry->installments->count() > 1 ? 'Parcela '.$installment->number.'/'.$entry->installments->count() : 'À vista' }}</td>
                            <td data-label="Vencimento" class="px-4 py-3 text-slate-600 tabular-nums">vence em {{ $installment->due_date->format('d/m/Y') }}</td>
                            <td data-label="Valor" class="px-4 py-3 text-right tabular-nums">{{ LocalizedDecimal::money($installment->amount) }}</td>
                            <td data-label="Situação" class="px-6 py-3 text-right"><span class="badge {{ $badge['classes'] }}">{{ $badge['label'] }}</span></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </section>
    @endif

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
                        <a href="{{ route('patients.treatments.photos.show', [$patient, $treatment, $photo]) }}" target="_blank" rel="noopener"
                           class="block overflow-hidden rounded-lg border border-slate-200 bg-slate-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-teal-700">
                            <img src="{{ route('patients.treatments.photos.show', [$patient, $treatment, $photo, 'miniatura' => 1]) }}"
                                 alt="Foto: {{ $label }}" loading="lazy" width="{{ $photo->width }}" height="{{ $photo->height }}"
                                 class="aspect-square w-full object-cover">
                        </a>
                        <div class="mt-1.5 flex items-start justify-between gap-2">
                            <p class="min-w-0 text-xs break-words text-slate-600">{{ $photo->caption ?: 'Sem legenda' }}</p>
                            @if ($canDetach)
                                <form method="POST" action="{{ route('patients.treatments.photos.destroy', [$patient, $treatment, $photo]) }}"
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
            <form method="POST" action="{{ route('patients.treatments.photos.store', [$patient, $treatment]) }}" enctype="multipart/form-data"
                  class="mt-6 border-t border-slate-200 pt-6" novalidate data-photo-form>
                @csrf
                <h3 class="text-sm font-semibold">Adicionar fotos</h3>
                <p class="mt-1 text-sm text-slate-600">
                    Até {{ $maxPhotos }} fotos por envio, em JPEG, PNG ou WebP. Localização e dados do aparelho são removidos antes de gravar.
                </p>

                <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-6">
                    <div class="md:col-span-6">
                        <label for="photos" class="label">Fotos <span class="text-red-600" aria-hidden="true">*</span></label>
                        <input id="photos" name="photos[]" type="file" multiple required accept="image/jpeg,image/png,image/webp"
                               data-photo-input data-max-files="{{ $maxPhotos }}" data-max-dimension="{{ config('clinic.photos.max_dimension') }}"
                               data-max-bytes="{{ App\Support\UploadLimits::photoBytes() }}" data-max-request-bytes="{{ App\Support\UploadLimits::requestBytes() }}"
                               @class(['input file:mr-3 file:cursor-pointer file:rounded-md file:border-0 file:bg-slate-100 file:px-3 file:py-1 file:text-sm file:font-medium', 'input-error' => $errors->has('photos') || $errors->has('photos.*')])>
                        <p class="mt-1 text-xs text-slate-500" data-photo-status aria-live="polite"></p>
                        @foreach (collect($errors->get('photos'))->merge(collect($errors->get('photos.*'))->flatten())->unique() as $message)
                            <p class="mt-1 text-sm text-red-700">{{ $message }}</p>
                        @endforeach
                    </div>
                    <x-form.select class="md:col-span-2" name="stage" label="Momento" :options="PhotoStage::options()" :selected="PhotoStage::Before->value" required />
                    <x-form.input class="md:col-span-4" name="caption" label="Legenda" maxlength="150" autocomplete="off" placeholder="Ex.: Perfil direito" />
                </div>

                <div class="mt-4 flex justify-end">
                    <button type="submit" class="btn btn-primary" data-photo-submit>Enviar fotos</button>
                </div>
            </form>
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

    @if ($treatment->isFinalized())
        <section class="card mt-6 p-6" aria-labelledby="evolucoes">
            <h2 id="evolucoes" class="text-sm font-semibold tracking-wide text-slate-500 uppercase">Evoluções e adendos</h2>

            @if ($treatment->notes->isEmpty())
                <p class="mt-4 text-sm text-slate-600">Nenhuma evolução registrada.</p>
            @else
                <ol class="mt-4 space-y-4">
                    @foreach ($treatment->notes as $note)
                        <li class="rounded-lg border border-slate-200 px-4 py-3">
                            <p class="text-xs text-slate-500">{{ ClinicTime::format($note->created_at) }} · {{ $note->author?->name ?? 'Usuário removido' }}</p>
                            <p class="mt-1 text-sm whitespace-pre-line">{{ $note->body }}</p>
                        </li>
                    @endforeach
                </ol>
            @endif

            @can('addNote', $treatment)
                <form method="POST" action="{{ route('patients.treatments.notes.store', [$patient, $treatment]) }}" class="mt-6 border-t border-slate-200 pt-6" novalidate>
                    @csrf
                    <x-form.textarea name="body" label="Nova evolução" required rows="3" maxlength="5000"
                                     hint="Como o paciente evoluiu, correções ao registro ou informações do retorno. Depois de salva, não pode ser editada." />
                    <div class="mt-4 flex justify-end">
                        <button type="submit" class="btn btn-primary">Registrar evolução</button>
                    </div>
                </form>
            @endcan
        </section>
    @endif

    <p class="mt-6 text-xs text-slate-500">
        Registrado em {{ ClinicTime::format($treatment->created_at) }} · Última atualização em {{ ClinicTime::format($treatment->updated_at) }}
    </p>

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
</x-layouts.app>
