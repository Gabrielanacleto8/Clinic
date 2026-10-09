{{-- Campos "de" e "até" dos filtros por período. Espera $filters e $span (classe de largura de cada campo). --}}
@foreach (['de' => 'De', 'ate' => 'Até'] as $field => $label)
    <div class="{{ $span }}">
        <label for="{{ $field }}" class="sr-only">{{ $label }}</label>
        <input id="{{ $field }}" name="{{ $field }}" type="date" value="{{ $filters[$field] }}" class="input" title="{{ $label }}">
    </div>
@endforeach
