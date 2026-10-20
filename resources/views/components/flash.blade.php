@foreach (['success' => 'border-emerald-200 bg-emerald-50 text-emerald-900', 'warning' => 'border-amber-200 bg-amber-50 text-amber-900', 'error' => 'border-red-200 bg-red-50 text-red-900'] as $type => $classes)
    @if (session($type))
        <div role="status" class="mb-6 rounded-lg border px-4 py-3 text-sm {{ $classes }}">
            {{ session($type) }}
            {{-- Atalho opcional, com URL montada no servidor (ex.: o atendimento recém-registrado). --}}
            @if ($type === 'success' && is_array(session('success_link')))
                <a href="{{ session('success_link')['url'] }}" class="ml-1 font-medium underline">{{ session('success_link')['label'] }}</a>
            @endif
        </div>
    @endif
@endforeach
