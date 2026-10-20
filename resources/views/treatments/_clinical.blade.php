{{-- O que foi feito e as orientações. Na janela da lista ($compact) fica em uma coluna só. --}}
@php $compact ??= false; @endphp
<div @class(['mt-6 grid grid-cols-1 gap-6', 'lg:grid-cols-3' => ! $compact])>
    <section @class(['card p-6', 'lg:col-span-2' => ! $compact])>
        <h2 class="text-sm font-semibold tracking-wide text-slate-500 uppercase">Procedimento</h2>
        <dl class="mt-4 space-y-4">
            <div>
                <dt class="text-xs text-slate-500">Áreas tratadas</dt>
                <dd class="mt-0.5 text-sm">{{ $treatment->treated_areas ?: '—' }}</dd>
            </div>
            <div>
                <dt class="text-xs text-slate-500">Avaliação</dt>
                <dd class="mt-0.5 text-sm whitespace-pre-line">{{ $treatment->assessment ?: '—' }}</dd>
            </div>
            <div>
                <dt class="text-xs text-slate-500">Descrição</dt>
                <dd class="mt-0.5 text-sm whitespace-pre-line">{{ $treatment->description }}</dd>
            </div>
        </dl>
    </section>

    <section class="card p-6">
        <h2 class="text-sm font-semibold tracking-wide text-slate-500 uppercase">Pós-procedimento</h2>
        <dl class="mt-4 space-y-4">
            <div>
                <dt class="text-xs text-slate-500">Orientações</dt>
                <dd class="mt-0.5 text-sm whitespace-pre-line">{{ $treatment->post_care ?: '—' }}</dd>
            </div>
            <div>
                <dt class="text-xs text-slate-500">Intercorrências</dt>
                <dd class="mt-0.5 text-sm whitespace-pre-line">{{ $treatment->complications ?: 'Nenhuma registrada' }}</dd>
            </div>
            <div>
                <dt class="text-xs text-slate-500">Retorno previsto</dt>
                <dd class="mt-0.5 text-sm">{{ $treatment->return_date?->format('d/m/Y') ?? '—' }}</dd>
            </div>
        </dl>
    </section>
</div>
