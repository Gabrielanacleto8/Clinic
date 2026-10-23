@props(['scope' => null])
@php
    // Resumo dos erros no topo de um formulário longo. Com "scope" (id de um
    // diálogo), só vale para o envio que partiu daquele diálogo; sem scope, só
    // para envios de fora de diálogos. O app.js leva o foco até aqui.
    $show = $errors->any() && ($scope === null ? old('_dialog') === null : old('_dialog') === $scope);
    $messages = $show ? array_values(array_unique($errors->all())) : [];
@endphp
@if ($messages !== [])
    <div role="alert" tabindex="-1" data-form-errors {{ $attributes->class('rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900') }}>
        <p class="font-semibold">{{ count($messages) === 1 ? 'Confira o campo destacado:' : 'Confira os campos destacados:' }}</p>
        <ul class="mt-1 list-inside list-disc space-y-0.5">
            @foreach ($messages as $message)
                <li>{{ $message }}</li>
            @endforeach
        </ul>
    </div>
@endif
