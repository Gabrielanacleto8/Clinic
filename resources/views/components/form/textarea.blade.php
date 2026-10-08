@props(['name', 'label', 'value' => null, 'hint' => null, 'scope' => null])
@php
    $id = $attributes->get('id', $name);
    // Ver o comentário sobre "scope" em form/input.blade.php.
    $inScope = $scope === null || old('_dialog') === $scope;
    $hasError = $inScope && $errors->has($name);
@endphp
<div {{ $attributes->only('class') }}>
    <label for="{{ $id }}" class="label">
        {{ $label }}
        @if ($attributes->get('required', false) !== false)
            <span class="text-red-600" aria-hidden="true">*</span>
        @endif
    </label>
    <textarea
        id="{{ $id }}"
        name="{{ $name }}"
        @if ($hasError) aria-invalid="true" aria-describedby="{{ $id }}-error" @endif
        {{ $attributes->except(['class', 'id'])->class(['input', 'input-error' => $hasError]) }}
    >{{ $inScope ? old($name, $value) : $value }}</textarea>
    @if ($hint)
        <p class="mt-1 text-xs text-slate-500">{{ $hint }}</p>
    @endif
    @if ($hasError)
        <p id="{{ $id }}-error" class="mt-1 text-sm text-red-700">{{ $errors->first($name) }}</p>
    @endif
</div>
