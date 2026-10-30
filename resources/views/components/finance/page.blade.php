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
        'inadimplencia' => $canView ? ['Inadimplência', route('finance.delinquency')] : null,
        'fixas' => $canView ? ['Despesas fixas', route('finance.recurring.index')] : null,
        'comissoes' => $canView ? ['Comissões', route('finance.commissions.index')] : null,
        'fluxo' => $canView ? ['Fluxo de caixa', route('finance.cashflow')] : null,
        'caixa' => $user->can('viewCash', FinancialEntry::class) ? ['Caixa do dia', route('finance.cash.show')] : null,
        'conciliacao' => $user->can('reconcile', FinancialEntry::class) ? ['Conciliação', route('finance.reconciliation')] : null,
        'comparativo' => $canView ? ['Comparativo mensal', route('finance.analysis')] : null,
        'receitas' => $canView ? ['Margem por procedimento', route('finance.margin')] : null,
        'profissionais' => $canView ? ['Margem e comissão por profissional', route('finance.margin', ['agrupar' => 'profissional'])] : null,
    ]);

    // Quatro abas principais, cada uma com as suas subabas.
    $groups = [
        'Visão geral' => ['geral'],
        'Movimentações' => ['receber', 'pagar', 'inadimplencia', 'fixas', 'comissoes'],
        'Caixa' => ['fluxo', 'caixa', 'conciliacao'],
        'Análises' => ['comparativo', 'receitas', 'profissionais'],
    ];
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

    <x-section-tabs :tabs="$tabs" :groups="$groups" :active="$active" label="Seções do financeiro" />

    {{ $slot }}
</x-layouts.app>
