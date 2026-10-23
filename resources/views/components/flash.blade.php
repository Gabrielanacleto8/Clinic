@foreach (['success' => 'border-emerald-200 bg-emerald-50 text-emerald-900', 'warning' => 'border-amber-200 bg-amber-50 text-amber-900', 'error' => 'border-red-200 bg-red-50 text-red-900'] as $type => $classes)
    @if (session($type))
        <div role="{{ $type === 'success' ? 'status' : 'alert' }}" class="mb-6 rounded-lg border px-4 py-3 text-sm {{ $classes }}">
            {{ session($type) }}
            {{-- Atalhos opcionais, com URL montada no servidor (ex.: o atendimento recém-registrado). --}}
            @foreach (array_filter([session($type.'_link'), ...(session($type.'_links') ?? [])], 'is_array') as $link)
                <a href="{{ $link['url'] }}" class="ml-1 font-medium underline">{{ $link['label'] }}</a>
            @endforeach
        </div>
    @endif
@endforeach
