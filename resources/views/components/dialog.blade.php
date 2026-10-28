@props(['id', 'title', 'size' => 'md'])
{{-- Janela modal nativa. Abre por [data-dialog-open="id"] e fecha por
     [data-dialog-close] (ver app.js). Reabre sozinha quando o formulário
     dela volta com erro: o formulário envia o campo oculto "_dialog".
     size="lg" é para formulários longos: a janela rola e o título fica fixo. --}}
<dialog id="{{ $id }}" aria-labelledby="{{ $id }}-title" @if (old('_dialog') === $id) data-dialog-autoshow @endif
        {{ $attributes->class([
            'm-auto w-[calc(100%-2rem)] rounded-xl border border-slate-200 bg-white p-0 text-slate-900 shadow-xl backdrop:bg-black/50',
            'max-w-md' => $size === 'md',
            'max-w-3xl' => $size === 'lg',
        ]) }}>
    <div class="sticky top-0 z-20 flex items-center justify-between gap-3 rounded-t-xl border-b border-slate-200 bg-white px-5 py-3.5">
        <h2 id="{{ $id }}-title" class="text-base font-semibold">{{ $title }}</h2>
        <button type="button" class="btn px-2 text-slate-500 hover:bg-slate-100 hover:text-slate-900 focus-visible:outline-teal-700" data-dialog-close aria-label="Fechar">
            <x-icon name="close" />
        </button>
    </div>
    <div class="px-5 py-4">
        {{ $slot }}
    </div>
</dialog>
