@php
    use App\Http\Requests\TreatmentPlanRequest;
    use App\Support\LocalizedDecimal;

    // No diálogo "Novo orçamento" da lista ($scope é o id dele), valores antigos e
    // erros só valem para o envio que partiu dele; fora de diálogo, sempre.
    $scope ??= null;
    $inScope = $scope === null || old('_dialog') === $scope;
    $fieldError = fn (string $key): ?string => $inScope ? $errors->first($key) ?: null : null;
    $section = $scope ? 'border-t border-slate-200 pt-5' : 'card p-6';

    // Linhas já salvas mais algumas em branco, dentro do limite aceito.
    $savedItems = ($inScope ? old('items') : null) ?? $plan->items->map(fn ($item) => [
        'procedure_id' => $item->procedure_id,
        'sessions' => $item->sessions,
        'unit_price' => LocalizedDecimal::moneyInput($item->unit_price),
    ])->all();
    $rows = min(TreatmentPlanRequest::MAX_ITEMS, max(3, count($savedItems) + 2));
    $discountLimit = app(App\Services\DiscountLimits::class)->for(auth()->user()->role);
@endphp
@csrf

@if ($scope)
    <input type="hidden" name="_dialog" value="{{ $scope }}">
    <x-form-errors :scope="$scope" />
@endif

@unless ($plan->exists)
    {{-- O paciente é escolhido só ao criar; editar não troca o paciente. --}}
    <section @class([$section])>
        <x-patient-picker :scope="$scope" :patient="$patient" :autofocus="$patient === null" />
    </section>
@endunless

<section @class([$section])>
    <h2 class="text-sm font-semibold tracking-wide text-slate-500 uppercase">Procedimentos</h2>
    <p class="mt-1 text-sm text-slate-600">O valor por sessão vem do catálogo e pode ser ajustado. Convertido em venda, o orçamento vira pacote: cada atendimento usa uma sessão, sem nova cobrança.</p>
    @if ($message = $fieldError('items'))
        <p class="mt-2 text-sm text-red-700">{{ $message }}</p>
    @endif

    <div class="mt-4 space-y-3" data-plan-items>
        @for ($i = 0; $i < $rows; $i++)
            @php
                $row = $savedItems[$i] ?? [];
                $procedureError = $fieldError("items.$i.procedure_id");
                $sessionsError = $fieldError("items.$i.sessions");
                $priceError = $fieldError("items.$i.unit_price");
                $filled = filled($row['procedure_id'] ?? null) || filled($row['sessions'] ?? null) || filled($row['unit_price'] ?? null);
            @endphp
            <fieldset class="grid grid-cols-2 gap-3 border-b border-slate-100 pb-3 last:border-b-0 sm:grid-cols-6" data-plan-item>
                <legend class="sr-only">Procedimento {{ $i + 1 }}</legend>
                <div class="col-span-2 sm:col-span-3">
                    <label for="items-{{ $i }}-procedure_id" class="label">Procedimento</label>
                    <select id="items-{{ $i }}-procedure_id" name="items[{{ $i }}][procedure_id]" data-plan-procedure
                            @class(['input', 'input-error' => $procedureError])>
                        <option value="">—</option>
                        @foreach ($procedures as $procedure)
                            <option value="{{ $procedure->id }}" data-price="{{ LocalizedDecimal::moneyInput($procedure->price) }}"
                                    @selected((string) ($row['procedure_id'] ?? '') === (string) $procedure->id)>{{ $procedure->name }}</option>
                        @endforeach
                    </select>
                    @if ($procedureError)
                        <p class="mt-1 text-sm text-red-700" data-plan-error>{{ $procedureError }}</p>
                    @endif
                </div>
                <div>
                    <label for="items-{{ $i }}-sessions" class="label">Sessões</label>
                    <input id="items-{{ $i }}-sessions" name="items[{{ $i }}][sessions]" type="number" min="1" max="{{ TreatmentPlanRequest::MAX_SESSIONS }}" inputmode="numeric"
                           value="{{ $row['sessions'] ?? '' }}" data-plan-sessions-input @class(['input', 'input-error' => $sessionsError])>
                    @if ($sessionsError)
                        <p class="mt-1 text-sm text-red-700" data-plan-error>{{ $sessionsError }}</p>
                    @endif
                </div>
                <div class="sm:col-span-2">
                    <label for="items-{{ $i }}-unit_price" class="label">Valor por sessão (R$)</label>
                    <input id="items-{{ $i }}-unit_price" name="items[{{ $i }}][unit_price]" type="text" inputmode="decimal" autocomplete="off"
                           value="{{ $row['unit_price'] ?? '' }}" data-plan-price @class(['input', 'input-error' => $priceError])>
                    @if ($priceError)
                        <p class="mt-1 text-sm text-red-700" data-plan-error>{{ $priceError }}</p>
                    @endif
                </div>
                {{-- Limpa a linha (app.js): linha em branco não é gravada. Só aparece com algo preenchido. --}}
                <div class="col-span-2 text-right sm:col-span-6">
                    <button type="button" class="cursor-pointer text-xs font-medium text-red-700 hover:underline" data-plan-remove @if (! $filled) hidden @endif>
                        Remover<span class="sr-only"> procedimento {{ $i + 1 }}</span>
                    </button>
                </div>
            </fieldset>
        @endfor
    </div>

    <p class="mt-4 text-right text-sm">
        {{-- Preço de tabela e desconto, calculados pelo app.js com o valor de catálogo de cada procedimento. --}}
        <span class="mr-3 text-slate-600" data-plan-discount hidden></span>
        Total: <strong class="text-base tabular-nums" data-plan-total aria-live="polite">—</strong>
    </p>
    @if ($discountLimit !== null)
        <p class="mt-1 text-right text-xs text-slate-500">Seu desconto máximo sobre o preço de tabela: {{ number_format($discountLimit, 1, ',', '.') }}%.</p>
    @endif
</section>

<section @class([$section, 'grid grid-cols-1 gap-4 md:grid-cols-6'])>
    <x-form.input class="md:col-span-2" :scope="$scope" name="valid_until" label="Válido até" type="date" :value="$plan->valid_until?->format('Y-m-d')"
                  hint="Depois dessa data, edite a validade antes de converter em venda." />
    <x-form.textarea class="md:col-span-6" :scope="$scope" name="notes" label="Observações" :value="$plan->notes" rows="3" maxlength="5000"
                     hint="Condições combinadas, intervalo entre sessões. Sai no orçamento impresso. Gravado com criptografia." />
</section>

<div class="flex flex-wrap items-center justify-end gap-3">
    @if ($scope)
        <button type="button" class="btn btn-secondary" data-dialog-close>Cancelar</button>
    @else
        <a href="{{ $plan->exists ? route('patients.plans.show', [$patient, $plan]) : $cancelUrl }}" class="btn btn-secondary">Cancelar</a>
    @endif
    @if ($plan->exists)
        <button type="submit" class="btn btn-primary">Salvar orçamento</button>
    @else
        {{-- "Criar orçamento" vem antes no HTML: o Enter num campo envia o primeiro botão, e ele não pode lançar uma venda.
             "Criar e converter em venda", para o paciente que já aceitou, segue para a forma de pagamento. --}}
        <button type="submit" class="btn btn-primary order-last">Criar orçamento</button>
        <button type="submit" name="convert" value="1" class="btn btn-secondary">Criar e converter em venda</button>
    @endif
</div>
