@props(['profile', 'document'])
{{-- Identificação da clínica no topo dos documentos impressos. --}}
@php $logoVersion = app(App\Services\ClinicLogo::class)->version(); @endphp
<div {{ $attributes->class('flex items-start gap-3') }}>
    @if ($logoVersion)
        <img src="{{ route('clinic.logo', ['v' => $logoVersion]) }}" alt="" class="size-12 shrink-0 object-contain">
    @endif
    <div class="min-w-0 text-xs text-slate-600">
        <p class="text-sm font-semibold text-slate-900">{{ $profile->displayName() }}</p>
        @foreach ($profile->documentLines() as $line)
            <p>{{ $line }}</p>
        @endforeach
        <p class="mt-1 font-medium tracking-wide text-slate-500 uppercase">{{ $document }}</p>
    </div>
</div>
