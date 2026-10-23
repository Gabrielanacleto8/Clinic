@php use App\Support\ClinicTime; @endphp
<x-layouts.app :title="'Comparar fotos · '.$patient->name" wide>
    <a href="{{ route('patients.show', [$patient, 'aba' => 'fotos']) }}#historico" class="text-sm text-slate-600 hover:underline">← {{ $patient->name }}</a>
    <h1 class="mt-1 text-xl font-semibold tracking-tight">Comparar fotos</h1>
    <p class="mt-1 text-sm text-slate-600">Lado a lado no computador, uma abaixo da outra no celular.</p>

    <div class="mt-6 grid grid-cols-1 gap-4 md:grid-cols-2">
        @foreach ($photos as $photo)
            @php
                $treatment = $photo->treatment;
                $label = $photo->stage->label().($photo->caption ? ' · '.$photo->caption : '');
            @endphp
            <figure class="card overflow-hidden">
                <img src="{{ route('patients.photos.show', [$patient, $photo, 'miniatura' => 1]) }}" alt="Foto: {{ $label }}"
                     width="{{ $photo->width }}" height="{{ $photo->height }}" class="w-full bg-slate-100 object-contain">
                <figcaption class="px-4 py-3 text-sm">
                    <p class="font-medium">
                        {{ $label }}
                        @if ($treatment?->isVoided())
                            <span class="badge bg-red-100 text-red-800">Anulado</span>
                        @endif
                    </p>
                    <p class="text-slate-600">
                        {{ $photo->takenOn()->format('d/m/Y') }} ·
                        @if ($treatment)
                            <a href="{{ route('patients.treatments.show', [$patient, $treatment]) }}" class="text-teal-800 hover:underline">{{ $treatment->procedure->name }}</a>
                        @else
                            Foto avulsa
                        @endif
                        · <a href="{{ route('patients.photos.show', [$patient, $photo]) }}" target="_blank" rel="noopener" class="text-teal-800 hover:underline">Tamanho real</a>
                    </p>
                </figcaption>
            </figure>
        @endforeach
    </div>
</x-layouts.app>
