@php
    use App\Support\ClinicTime;
    use App\Support\LocalizedDecimal;
@endphp
<section class="card mt-4 overflow-x-auto" aria-labelledby="produtos-utilizados">
    <h2 id="produtos-utilizados" class="sr-only">Produtos utilizados</h2>
    @if ($records->isEmpty())
        <p class="px-4 py-10 text-center text-sm text-slate-500">Nenhum produto aplicado em atendimentos finalizados.</p>
    @else
        <table class="w-full min-w-2xl text-left text-sm">
            <thead class="border-b border-slate-200 bg-slate-50 text-xs tracking-wide text-slate-500 uppercase">
                <tr>
                    <th scope="col" class="px-4 py-3 font-medium">Data</th>
                    <th scope="col" class="px-4 py-3 font-medium">Produto</th>
                    <th scope="col" class="px-4 py-3 font-medium">Lote</th>
                    <th scope="col" class="px-4 py-3 text-right font-medium">Quantidade</th>
                    <th scope="col" class="px-4 py-3 font-medium">Atendimento</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @foreach ($records as $used)
                    <tr>
                        <td class="px-4 py-3 whitespace-nowrap text-slate-600 tabular-nums">{{ ClinicTime::format($used->treatment->performed_at, 'd/m/Y') }}</td>
                        <td class="px-4 py-3 font-medium">{{ $used->batch->product->name }}</td>
                        <td class="px-4 py-3 whitespace-nowrap text-slate-600 tabular-nums">
                            {{ $used->batch->batch_code }}@if ($used->batch->expires_on) · val. {{ $used->batch->expires_on->format('d/m/Y') }}@endif
                        </td>
                        <td class="px-4 py-3 text-right whitespace-nowrap tabular-nums">{{ LocalizedDecimal::quantity($used->quantity) }} {{ $used->batch->product->unit->symbol() }}</td>
                        <td class="px-4 py-3">
                            <a href="{{ route('patients.treatments.show', [$patient, $used->treatment]) }}" class="text-teal-800 hover:underline">{{ $used->treatment->procedure->name }}</a>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</section>

<div class="mt-4">{{ $records->links() }}</div>
