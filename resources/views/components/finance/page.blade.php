@props(['active', 'title' => 'Financeiro', 'subtitle' => null])
@php
    use App\Models\FinancialEntry;

    $user = auth()->user();
    $canView = $user->can('viewAny', FinancialEntry::class);

    // Cada aba só aparece para quem pode abri-la. "Caixa do dia" é a única de quem fecha o caixa sem ver o financeiro.
    $tabs = array_filter([
        'geral' => $canView ? ['Visão geral', route('finance.index')] : null,
        'receber' => $canView ? ['A receber', route('finance.receivable')] : null,
        'pagar' => $canView ? ['A pagar', route('finance.payable')] : null,
        'fluxo' => $canView ? ['Fluxo de caixa', route('finance.cashflow')] : null,
        'caixa' => $user->can('viewCash', FinancialEntry::class) ? ['Caixa do dia', route('finance.cash.show')] : null,
        'conciliacao' => $user->can('reconcile', FinancialEntry::class) ? ['Conciliação', route('finance.reconciliation')] : null,
        'inadimplencia' => $canView ? ['Inadimplência', route('finance.delinquency')] : null,
        'receitas' => $canView ? ['Receitas e margem', route('finance.margin')] : null,
        'comissoes' => $canView ? ['Comissões', route('finance.commissions.index')] : null,
        'fixas' => $canView ? ['Despesas fixas', route('finance.recurring.index')] : null,
    ]);

    $tabClass = fn (bool $current) => 'rounded-lg px-3 py-1.5 text-sm font-medium whitespace-nowrap '.($current ? 'bg-brand text-on-brand' : 'text-slate-700 hover:bg-slate-100');
@endphp
{{-- Moldura das abas do financeiro: título, ações e navegação. --}}
<x-layouts.app :title="$title">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold tracking-tight">{{ $title }}</h1>
            @if ($subtitle)
                <p class="mt-1 text-sm text-slate-600">{{ $subtitle }}</p>
            @endif
        </div>
        @isset($actions)
            <div class="flex flex-wrap items-center gap-2">{{ $actions }}</div>
        @endisset
    </div>

    @if (count($tabs) > 1)
        <nav class="mt-4 flex gap-1 relative overflow-x-auto rounded-xl border border-slate-200 bg-white p-1" aria-label="Seções do financeiro" data-tabs>
            @foreach ($tabs as $key => [$label, $url])
                <a href="{{ $url }}" class="{{ $tabClass($active === $key) }}" @if ($active === $key) aria-current="page" @endif>{{ $label }}</a>
            @endforeach
        </nav>
    @endif

    {{ $slot }}
</x-layouts.app>
