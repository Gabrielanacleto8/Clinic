@php
    use App\Http\Requests\CommissionRatesRequest;

    $rateText = fn ($rate) => $rate === null ? '' : number_format((float) $rate, 2, ',', '');
    // Exceções gravadas e três linhas em branco para novas.
    $saved = old('exceptions', $rules->flatten()->filter(fn ($rule) => $rule->procedure_id !== null)->map(fn ($rule) => [
        'user_id' => $rule->user_id,
        'procedure_id' => $rule->procedure_id,
        'rate' => $rateText($rule->rate),
    ])->values()->all());
    $rows = min(CommissionRatesRequest::MAX_EXCEPTIONS, count($saved) + 3);
@endphp
<x-finance.page active="comissoes" title="Regras de comissão" subtitle="Valem para os atendimentos registrados a partir de agora. Os já registrados guardam o % da época.">
    <a href="{{ route('finance.commissions.index') }}" class="mt-4 inline-block text-sm text-slate-600 hover:underline">← Comissões</a>

    <form method="POST" action="{{ route('finance.commissions.rates.update') }}" class="mt-4 space-y-6" novalidate>
        @csrf
        @method('PUT')

        <section class="card relative overflow-x-auto" aria-labelledby="padrao">
            <h2 id="padrao" class="px-4 pt-5 text-sm font-semibold tracking-wide text-slate-500 uppercase sm:px-6">% padrão por profissional</h2>
            <p class="px-4 pt-1 text-xs text-slate-500 sm:px-6">Em branco: sem comissão, salvo as exceções abaixo.</p>
            @error('defaults')
                <p class="px-4 pt-2 text-sm text-red-700 sm:px-6">{{ $message }}</p>
            @enderror
            <table class="table-stack mt-3 w-full min-w-md text-left text-sm">
                <thead class="border-y border-slate-200 bg-slate-50 text-xs tracking-wide text-slate-500 uppercase">
                    <tr>
                        <th scope="col" class="px-4 py-3 font-medium sm:px-6">Profissional</th>
                        <th scope="col" class="px-4 py-3 font-medium sm:px-6">Comissão (%)</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($professionals as $professional)
                        @php
                            $default = $rules->get($professional->id)?->firstWhere('procedure_id', null);
                            $field = 'defaults.'.$professional->id;
                        @endphp
                        <tr>
                            <th scope="row" class="px-4 py-3 font-medium sm:px-6">
                                {{ $professional->name }}
                                @unless ($professional->is_active)
                                    <span class="badge ml-1 bg-slate-200 text-slate-700">Inativo</span>
                                @endunless
                            </th>
                            <td data-label="Comissão (%)" class="px-4 py-3 sm:px-6">
                                <label for="default-{{ $professional->id }}" class="sr-only">Comissão padrão de {{ $professional->name }}</label>
                                <input id="default-{{ $professional->id }}" name="defaults[{{ $professional->id }}]" type="text" inputmode="decimal" autocomplete="off"
                                       value="{{ old($field, $rateText($default?->rate)) }}" placeholder="0,00" @class(['input max-w-32', 'input-error' => $errors->has($field)])>
                                @error($field)
                                    <p class="mt-1 text-sm text-red-700">{{ $message }}</p>
                                @enderror
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </section>

        <section class="card p-6" aria-labelledby="excecoes">
            <h2 id="excecoes" class="text-sm font-semibold tracking-wide text-slate-500 uppercase">Exceções por procedimento</h2>
            <p class="mt-1 text-sm text-slate-600">Substituem o % padrão daquele profissional no procedimento. Use 0 para um procedimento sem comissão. Para apagar, deixe o profissional em branco.</p>
            @error('exceptions')
                <p class="mt-2 text-sm text-red-700">{{ $message }}</p>
            @enderror
            <div class="mt-4 space-y-3">
                @for ($i = 0; $i < $rows; $i++)
                    @php $row = $saved[$i] ?? []; @endphp
                    <fieldset class="grid grid-cols-1 gap-3 border-b border-slate-100 pb-3 last:border-b-0 sm:grid-cols-6">
                        <legend class="sr-only">Exceção {{ $i + 1 }}</legend>
                        <x-form.select class="sm:col-span-2" id="exceptions-{{ $i }}-user_id" name="exceptions[{{ $i }}][user_id]" label="Profissional" placeholder="—"
                                       :options="$professionals->pluck('name', 'id')->all()" :selected="$row['user_id'] ?? null" />
                        <x-form.select class="sm:col-span-3" id="exceptions-{{ $i }}-procedure_id" name="exceptions[{{ $i }}][procedure_id]" label="Procedimento" placeholder="—"
                                       :options="$procedures->all()" :selected="$row['procedure_id'] ?? null" />
                        <x-form.input id="exceptions-{{ $i }}-rate" name="exceptions[{{ $i }}][rate]" label="Comissão (%)" inputmode="decimal" autocomplete="off" :value="$row['rate'] ?? null" />
                    </fieldset>
                @endfor
            </div>
        </section>

        <div class="flex justify-end">
            <button type="submit" class="btn btn-primary">Salvar regras</button>
        </div>
    </form>
</x-finance.page>
