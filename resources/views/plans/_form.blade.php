@php
    use App\Http\Requests\TreatmentPlanRequest;
    use App\Support\LocalizedDecimal;

    // Linhas já salvas mais algumas em branco, dentro do limite aceito.
    $savedItems = old('items', $plan->items->map(fn ($item) => [
        'procedure_id' => $item->procedure_id,
        'sessions' => $item->sessions,
        'unit_price' => LocalizedDecimal::moneyInput($item->unit_price),
    ])->all());
    $rows = min(TreatmentPlanRequest::MAX_ITEMS, max(3, count($savedItems) + 2));
@endphp
@csrf

<section class="card p-6">
    <h2 class="text-sm font-semibold tracking-wide text-slate-500 uppercase">Procedimentos</h2>
    <p class="mt-1 text-sm text-slate-600">O valor por sessão vem do catálogo e pode ser ajustado. Aprovado, o orçamento vira pacote: cada atendimento usa uma sessão, sem nova cobrança.</p>
    @error('items')
        <p class="mt-2 text-sm text-red-700">{{ $message }}</p>
    @enderror

    <div class="mt-4 space-y-3" data-plan-items>
        @for ($i = 0; $i < $rows; $i++)
            @php $row = $savedItems[$i] ?? []; @endphp
            <fieldset class="grid grid-cols-2 gap-3 border-b border-slate-100 pb-3 last:border-b-0 sm:grid-cols-6" data-plan-item>
                <legend class="sr-only">Procedimento {{ $i + 1 }}</legend>
                <div class="col-span-2 sm:col-span-3">
                    <label for="items-{{ $i }}-procedure_id" class="label">Procedimento</label>
                    <select id="items-{{ $i }}-procedure_id" name="items[{{ $i }}][procedure_id]" data-plan-procedure
                            @class(['input', 'input-error' => $errors->has("items.$i.procedure_id")])>
                        <option value="">—</option>
                        @foreach ($procedures as $procedure)
                            <option value="{{ $procedure->id }}" data-price="{{ LocalizedDecimal::moneyInput($procedure->price) }}"
                                    @selected((string) ($row['procedure_id'] ?? '') === (string) $procedure->id)>{{ $procedure->name }}</option>
                        @endforeach
                    </select>
                    @error("items.$i.procedure_id")
                        <p class="mt-1 text-sm text-red-700">{{ $message }}</p>
                    @enderror
                </div>
                <div>
                    <label for="items-{{ $i }}-sessions" class="label">Sessões</label>
                    <input id="items-{{ $i }}-sessions" name="items[{{ $i }}][sessions]" type="number" min="1" max="{{ TreatmentPlanRequest::MAX_SESSIONS }}" inputmode="numeric"
                           value="{{ $row['sessions'] ?? '' }}" data-plan-sessions-input @class(['input', 'input-error' => $errors->has("items.$i.sessions")])>
                    @error("items.$i.sessions")
                        <p class="mt-1 text-sm text-red-700">{{ $message }}</p>
                    @enderror
                </div>
                <div class="sm:col-span-2">
                    <label for="items-{{ $i }}-unit_price" class="label">Valor por sessão (R$)</label>
                    <input id="items-{{ $i }}-unit_price" name="items[{{ $i }}][unit_price]" type="text" inputmode="decimal" autocomplete="off"
                           value="{{ $row['unit_price'] ?? '' }}" data-plan-price @class(['input', 'input-error' => $errors->has("items.$i.unit_price")])>
                    @error("items.$i.unit_price")
                        <p class="mt-1 text-sm text-red-700">{{ $message }}</p>
                    @enderror
                </div>
            </fieldset>
        @endfor
    </div>

    <p class="mt-4 text-right text-sm">Total: <strong class="text-base tabular-nums" data-plan-total aria-live="polite">—</strong></p>
</section>

<section class="card grid grid-cols-1 gap-4 p-6 md:grid-cols-6">
    <x-form.input class="md:col-span-2" name="valid_until" label="Válido até" type="date" :value="$plan->valid_until?->format('Y-m-d')"
                  hint="Depois dessa data, edite a validade antes de aprovar." />
    <x-form.textarea class="md:col-span-6" name="notes" label="Observações" :value="$plan->notes" rows="3" maxlength="5000"
                     hint="Condições combinadas, intervalo entre sessões. Sai no orçamento impresso. Gravado com criptografia." />
</section>

<div class="flex flex-wrap items-center justify-end gap-3">
    <a href="{{ $plan->exists ? route('patients.plans.show', [$patient, $plan]) : route('patients.show', $patient) }}" class="btn btn-secondary">Cancelar</a>
    <button type="submit" class="btn btn-primary">{{ $plan->exists ? 'Salvar orçamento' : 'Criar orçamento' }}</button>
</div>
