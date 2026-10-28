@php
    use App\Support\ClinicTime;

    $canAttach = auth()->user()->can('attachPhotos', $patient);
    $canDetach = auth()->user()->can('detachPhotos', $patient);
    $photoErrors = $errors->hasAny(['photos', 'photos.*', 'stage', 'caption', 'taken_on']);
@endphp
<section class="card mt-4 p-6" aria-labelledby="fotos-do-paciente">
    <h2 id="fotos-do-paciente" class="sr-only">Fotos</h2>
    <p class="text-sm text-slate-600">
        {{ $patient->image_use_consented_at ? 'Paciente autorizou o uso das fotos em divulgação.' : 'Sem autorização para divulgação: as fotos ficam só no prontuário.' }}
    </p>

    @if ($canAttach)
        {{-- O envio fica no topo, recolhido: no celular, antes das miniaturas. Reabre sozinho quando volta com erro. --}}
        <details class="mt-4 rounded-lg border border-slate-200" @if ($photoErrors) open @endif>
            <summary class="cursor-pointer px-4 py-2 text-sm font-medium text-teal-800 select-none">Adicionar fotos avulsas</summary>
            <div class="px-4 pb-4">
                <x-photos.upload-form :action="route('patients.photos.store', $patient)" title="Fotos avulsas" :stage="false" :bordered="false"
                                      description="Para fotos anteriores ao sistema ou de procedimentos sem atendimento registrado.">
                    <x-form.input class="md:col-span-2" name="taken_on" type="date" label="Data da foto" :max="ClinicTime::today()" required />
                </x-photos.upload-form>
            </div>
        </details>
    @endif

    @if ($records->isEmpty())
        <p class="py-6 text-center text-sm text-slate-500">Nenhuma foto deste paciente.</p>
    @else
        {{-- Marcar duas fotos abre a comparação lado a lado (app.js habilita o botão). --}}
        <form method="GET" action="{{ route('patients.photos.compare', $patient) }}" class="mt-4" data-compare-form data-allow-resubmit>
            <div class="flex flex-wrap items-center justify-between gap-2">
                <p class="text-xs text-slate-500">Marque duas fotos para ver lado a lado, por exemplo antes e depois.</p>
                <button type="submit" class="btn btn-secondary" data-compare-submit disabled>Comparar (0/2)</button>
            </div>
            <ul class="mt-3 grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
                @foreach ($records as $photo)
                    @php
                        $treatment = $photo->treatment;
                        $label = $photo->stage->label().($photo->caption ? ' · '.$photo->caption : '');
                    @endphp
                    <li class="relative">
                        <label class="absolute top-2 left-2 z-10 flex items-center gap-1 rounded-md bg-white/90 px-1.5 py-0.5 text-xs shadow">
                            <input type="checkbox" name="fotos[]" value="{{ $photo->id }}" class="size-4 rounded border-slate-300 accent-brand" data-compare-pick>
                            <span class="sr-only">Comparar esta foto</span>
                        </label>
                        <a href="{{ route('patients.photos.show', [$patient, $photo]) }}" target="_blank" rel="noopener"
                           class="block overflow-hidden rounded-lg border border-slate-200 bg-slate-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-teal-700">
                            <img src="{{ route('patients.photos.show', [$patient, $photo, 'miniatura' => 1]) }}"
                                 alt="Foto: {{ $label }}" loading="lazy" width="{{ $photo->width }}" height="{{ $photo->height }}" class="aspect-square w-full object-cover">
                        </a>
                        <div class="mt-1.5 flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <p class="text-xs font-medium break-words">
                                    {{ $label }}
                                    @if ($treatment?->isVoided())
                                        <span class="badge bg-red-100 text-red-800">Anulado</span>
                                    @endif
                                </p>
                                <p class="text-xs text-slate-500">
                                    {{ $photo->takenOn()->format('d/m/Y') }} ·
                                    @if ($treatment)
                                        <a href="{{ route('patients.treatments.show', [$patient, $treatment]) }}" class="text-teal-800 hover:underline">{{ $treatment->procedure->name }}</a>
                                    @else
                                        Foto avulsa
                                    @endif
                                </p>
                            </div>
                            {{-- Foto de atendimento se remove na página dele. O formulário vai fora do GET de comparação, por isso o "form" por atributo. --}}
                            @if ($canDetach && ! $treatment)
                                <button type="submit" form="remove-photo-{{ $photo->id }}" class="cursor-pointer text-xs font-medium text-red-700 hover:underline">Remover</button>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        </form>
        @if ($canDetach)
            @foreach ($records as $photo)
                @unless ($photo->treatment)
                    <form id="remove-photo-{{ $photo->id }}" method="POST" action="{{ route('patients.photos.destroy', [$patient, $photo]) }}" data-confirm="Remover esta foto do paciente?">
                        @csrf
                        @method('DELETE')
                    </form>
                @endunless
            @endforeach
        @endif
    @endif
</section>

<div class="mt-4">{{ $records->links() }}</div>
