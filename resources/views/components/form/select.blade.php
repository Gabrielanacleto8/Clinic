@props(['name', 'label', 'options' => [], 'selected' => null, 'placeholder' => null, 'scope' => null])
@php
    $id = $attributes->get('id', $name);
    // Ver o comentário sobre "scope" em form/input.blade.php.
    $inScope = $scope === null || old('_dialog') === $scope;
    $key = str_replace(['[', ']'], ['.', ''], $name);
    $hasError = $inScope && $errors->has($key);
    $current = (string) ($inScope ? old($key, $selected) : $selected);
@endphp
<div {{ $attributes->only('class') }}>
    <label for="{{ $id }}" class="label">
        {{ $label }}
        @if ($attributes->get('required', false) !== false)
            <span class="text-red-600" aria-hidden="true">*</span>
        @endif
    </label>
    <select
        id="{{ $id }}"
        name="{{ $name }}"
        @if ($hasError) aria-invalid="true" aria-describedby="{{ $id }}-error" @endif
        {{ $attributes->except(['class', 'id'])->class(['input', 'input-error' => $hasError]) }}
    >
        @if ($placeholder !== null)
            <option value="">{{ $placeholder }}</option>
        @endif
        @foreach ($options as $optionValue => $optionLabel)
            <option value="{{ $optionValue }}" @selected($current === (string) $optionValue)>{{ $optionLabel }}</option>
        @endforeach
    </select>
    @if ($hasError)
        <p id="{{ $id }}-error" class="mt-1 text-sm text-red-700">{{ $errors->first($key) }}</p>
    @endif
</div>
