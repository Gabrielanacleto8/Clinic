@php
    use App\Models\FinancialEntry;
    use App\Support\LocalizedDecimal;

    $pendingTotal = $summary->sum('pending');
@endphp
<x-finance.page active="comissoes" title="Comissões e repasses" :subtitle="LocalizedDecimal::money($pendingTotal).' a repassar, pelos atendimentos até hoje'">
    @can('manage', FinancialEntry::class)
        <x-slot:actions>
            <a href="{{ route('finance.commissions.rates') }}" class="btn btn-secondary">Regras de comissão</a>
        </x-slot:actions>
    @endcan

    <p class="mt-4 text-sm text-slate-600">
        A comissão é o % do profissional sobre o que o paciente pagou do atendimento, menos estornos. Na sessão de pacote, sobre o valor da sessão, na proporção do pacote já pago.
        Cada atendimento guarda o % que valia quando foi registrado.
    </p>

    <section class="card mt-6 relative overflow-x-auto" aria-labelledby="profissionais">
        <h2 id="profissionais" class="sr-only">Profissionais</h2>
        @if ($professionals->isEmpty())
            <p class="px-4 py-10 text-center text-sm text-slate-500">
                Nenhuma regra de comissão cadastrada.
                @can('manage', FinancialEntry::class)
                    <a href="{{ route('finance.commissions.rates') }}" class="font-medium text-teal-800 hover:underline">Cadastrar regras</a>
                @endcan
            </p>
        @else
            <table class="table-stack w-full min-w-2xl text-left text-sm">
                <thead class="border-b border-slate-200 bg-slate-50 text-xs tracking-wide text-slate-500 uppercase">
                    <tr>
                        <th scope="col" class="px-4 py-3 font-medium sm:px-6">Profissional</th>
                        <th scope="col" class="px-4 py-3 font-medium">Regra</th>
                        <th scope="col" class="px-4 py-3 text-right font-medium">Comissão</th>
                        <th scope="col" class="px-4 py-3 text-right font-medium">Já repassado</th>
                        <th scope="col" class="px-4 py-3 text-right font-medium sm:px-6">A repassar</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($professionals as $professional)
                        @php $row = $summary->get($professional->id); @endphp
                        <tr>
                            <td data-label="Profissional" class="px-4 py-3 sm:px-6">
                                <a href="{{ route('finance.commissions.show', $professional) }}" class="font-medium text-teal-800 hover:underline">{{ $professional->name }}</a>
                                @unless ($professional->is_active)
                                    <span class="badge ml-1 bg-slate-200 text-slate-700">Inativo</span>
                                @endunless
                            </td>
                            <td data-label="Regra" class="px-4 py-3 text-slate-600">
                                {{ isset($defaults[$professional->id]) ? number_format((float) $defaults[$professional->id], 2, ',', '.').'%' : 'Sem % padrão' }}
                                @if ($exceptions[$professional->id] ?? 0)
                                    <span class="block text-xs">+ {{ App\Support\Plural::of((int) $exceptions[$professional->id], 'exceção', 'exceções') }} por procedimento</span>
                                @endif
                            </td>
                            <td data-label="Comissão" class="px-4 py-3 text-right tabular-nums">{{ LocalizedDecimal::money($row->earned ?? 0) }}</td>
                            <td data-label="Já repassado" class="px-4 py-3 text-right tabular-nums text-slate-600">{{ LocalizedDecimal::money($row->paid ?? 0) }}</td>
                            <td data-label="A repassar" @class(['px-4 py-3 text-right font-semibold tabular-nums sm:px-6', 'text-red-700' => ($row->pending ?? 0) < 0])>{{ LocalizedDecimal::money($row->pending ?? 0) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </section>
</x-finance.page>
