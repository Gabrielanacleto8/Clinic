@php use App\Support\ClinicTime; @endphp
<section class="card mt-4 p-6" aria-labelledby="fotos-do-paciente">
    <h2 id="fotos-do-paciente" class="sr-only">Fotos</h2>
    @if ($records->isEmpty())
        <p class="py-6 text-center text-sm text-slate-500">Nenhuma foto nos atendimentos deste paciente.</p>
    @else
        <ul class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
            @foreach ($records as $photo)
                @php
                    $treatment = $photo->treatment;
                    $label = $photo->stage->label().($photo->caption ? ' · '.$photo->caption : '');
                @endphp
                <li>
                    <a href="{{ route('patients.treatments.photos.show', [$patient, $treatment, $photo]) }}" target="_blank" rel="noopener"
                       class="block overflow-hidden rounded-lg border border-slate-200 bg-slate-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-teal-700">
                        <img src="{{ route('patients.treatments.photos.show', [$patient, $treatment, $photo, 'miniatura' => 1]) }}"
                             alt="Foto: {{ $label }}" loading="lazy" width="{{ $photo->width }}" height="{{ $photo->height }}" class="aspect-square w-full object-cover">
                    </a>
                    <p class="mt-1.5 text-xs font-medium">{{ $label }}</p>
                    <p class="text-xs text-slate-500">
                        {{ ClinicTime::format($treatment->performed_at, 'd/m/Y') }} ·
                        <a href="{{ route('patients.treatments.show', [$patient, $treatment]) }}" class="text-teal-800 hover:underline">{{ $treatment->procedure->name }}</a>
                    </p>
                </li>
            @endforeach
        </ul>
    @endif
</section>

<div class="mt-4">{{ $records->links() }}</div>
