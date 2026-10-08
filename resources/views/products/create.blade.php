<x-layouts.app title="Novo produto">
    <div class="mx-auto max-w-3xl">
        <a href="{{ route('products.index') }}" class="text-sm text-slate-600 hover:underline">← Estoque</a>
        <h1 class="mt-1 text-xl font-semibold tracking-tight">Novo produto</h1>

        <form method="POST" action="{{ route('products.store') }}" class="mt-6 space-y-6" novalidate>
            @include('products._form')
        </form>
    </div>
</x-layouts.app>
