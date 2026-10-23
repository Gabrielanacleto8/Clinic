{{-- Campos "de" e "até" dos filtros por período. Espera $filters e $span (classe de largura de cada campo). --}}
@foreach (['de' => 'De', 'ate' => 'Até'] as $field => $label)
    <div class="{{ $span }} flex items-center gap-2">
        <label for="{{ $field }}" class="shrink-0 text-sm text-slate-600">{{ $label }}</label>
        <input id="{{ $field }}" name="{{ $field }}" type="date" value="{{ $filters[$field] }}" class="input min-w-0">
    </div>
@endforeach
