@props(['name', 'label', 'type' => 'text', 'value' => null, 'hint' => null, 'scope' => null])
@php
    $id = $attributes->get('id', $name);
    // Com "scope" (o id de um diálogo), valores antigos e erros só valem se o
    // envio que falhou partiu daquele diálogo. Evita que um diálogo mostre o
    // erro de outro quando os dois têm campos com o mesmo nome.
    $inScope = $scope === null || old('_dialog') === $scope;
    // Campo de lista (items[0][name]): valores antigos e erros usam a chave com pontos.
    $key = str_replace(['[', ']'], ['.', ''], $name);
    $hasError = $inScope && $errors->has($key);
    // Senhas nunca são devolvidas ao formulário.
    $current = $type === 'password' ? null : ($inScope ? old($key, $value) : $value);
    // Dica e erro são anunciados junto do campo pelo leitor de tela.
    $describedBy = trim(($hint ? "{$id}-hint " : '').($hasError ? "{$id}-error" : ''));
@endphp
<div {{ $attributes->only('class') }}>
    <label for="{{ $id }}" class="label">
        {{ $label }}
        @if ($attributes->get('required', false) !== false)
            <span class="text-red-600" aria-hidden="true">*</span>
        @endif
    </label>
    <input
        id="{{ $id }}"
        name="{{ $name }}"
        type="{{ $type }}"
        @if ($current !== null) value="{{ $current }}" @endif
        @if ($hasError) aria-invalid="true" @endif
        @if ($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif
        {{ $attributes->except(['class', 'id'])->class(['input', 'input-error' => $hasError]) }}
    >
    @if ($hint)
        <p id="{{ $id }}-hint" class="mt-1 text-xs text-slate-500">{{ $hint }}</p>
    @endif
    @if ($hasError)
        <p id="{{ $id }}-error" class="mt-1 text-sm text-red-700">{{ $errors->first($key) }}</p>
    @endif
</div>
