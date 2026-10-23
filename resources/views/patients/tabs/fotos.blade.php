@php
    use App\Support\ClinicTime;

    $canAttach = auth()->user()->can('attachPhotos', $patient);
    $canDetach = auth()->user()->can('detachPhotos', $patient);
@endphp
<section class="card mt-4 p-6" aria-labelledby="fotos-do-paciente">
    <h2 id="fotos-do-paciente" class="sr-only">Fotos</h2>
    <p class="mb-4 text-sm text-slate-600">
        {{ $patient->image_use_consented_at ? 'Paciente autorizou o uso das fotos em divulgação.' : 'Sem autorização para divulgação: as fotos ficam só no prontuário.' }}
    </p>
    @if ($records->isEmpty())
        <p class="py-6 text-center text-sm text-slate-500">Nenhuma foto deste paciente.</p>
    @else
        <ul class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
            @foreach ($records as $photo)
                @php
                    $treatment = $photo->treatment;
                    $label = $photo->stage->label().($photo->caption ? ' · '.$photo->caption : '');
                @endphp
                <li>
                    <a href="{{ route('patients.photos.show', [$patient, $photo]) }}" target="_blank" rel="noopener"
                       class="block overflow-hidden rounded-lg border border-slate-200 bg-slate-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-teal-700">
                        <img src="{{ route('patients.photos.show', [$patient, $photo, 'miniatura' => 1]) }}"
                             alt="Foto: {{ $label }}" loading="lazy" width="{{ $photo->width }}" height="{{ $photo->height }}" class="aspect-square w-full object-cover">
                    </a>
                    <div class="mt-1.5 flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            <p class="text-xs font-medium break-words">{{ $label }}</p>
                            <p class="text-xs text-slate-500">
                                {{ $photo->takenOn()->format('d/m/Y') }} ·
                                @if ($treatment)
                                    <a href="{{ route('patients.treatments.show', [$patient, $treatment]) }}" class="text-teal-800 hover:underline">{{ $treatment->procedure->name }}</a>
                                @else
                                    Foto avulsa
                                @endif
                            </p>
                        </div>
                        {{-- Foto de atendimento se remove na página dele. --}}
                        @if ($canDetach && ! $treatment)
                            <form method="POST" action="{{ route('patients.photos.destroy', [$patient, $photo]) }}" data-confirm="Remover esta foto do paciente?">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="cursor-pointer text-xs font-medium text-red-700 hover:underline">Remover</button>
                            </form>
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>
    @endif

    @if ($canAttach)
        <x-photos.upload-form :action="route('patients.photos.store', $patient)" title="Adicionar fotos avulsas"
                              description="Para fotos anteriores ao sistema ou de procedimentos sem atendimento registrado.">
            <x-form.input class="md:col-span-2" name="taken_on" type="date" label="Data da foto" :max="ClinicTime::today()" required />
        </x-photos.upload-form>
    @endif
</section>

<div class="mt-4">{{ $records->links() }}</div>
