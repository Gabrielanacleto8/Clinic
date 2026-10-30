@props(['patient', 'size' => 'sm'])
@php
    // Arquivado não tem rota de foto: aparece só com as iniciais.
    $photo = $patient->avatar_path !== null && ! $patient->trashed();
    $sizeClasses = $size === 'lg' ? 'size-16 text-xl' : 'size-9 text-xs';
@endphp
{{-- Foto de identificação do paciente, ou as iniciais quando não há foto. O nome
     aparece sempre ao lado, então a imagem é decorativa para leitores de tela. --}}
@if ($photo)
    <img src="{{ route('patients.avatar.show', $patient) }}" alt="" loading="lazy" width="{{ $size === 'lg' ? 64 : 36 }}" height="{{ $size === 'lg' ? 64 : 36 }}"
         {{ $attributes->class([$sizeClasses, 'shrink-0 rounded-full bg-slate-200 object-cover']) }}>
@else
    <span aria-hidden="true" {{ $attributes->class([$sizeClasses, 'flex shrink-0 items-center justify-center rounded-full bg-slate-100 font-semibold text-slate-800']) }}>{{ $patient->initials() }}</span>
@endif
