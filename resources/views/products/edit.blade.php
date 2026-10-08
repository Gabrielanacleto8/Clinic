<x-layouts.app :title="'Editar '.$product->name">
    <div class="mx-auto max-w-3xl">
        <a href="{{ route('products.show', $product) }}" class="text-sm text-slate-600 hover:underline">← {{ $product->name }}</a>
        <h1 class="mt-1 text-xl font-semibold tracking-tight">Editar produto</h1>

        <form method="POST" action="{{ route('products.update', $product) }}" class="mt-6 space-y-6" novalidate>
            @method('PUT')
            @include('products._form')
        </form>
    </div>
</x-layouts.app>
