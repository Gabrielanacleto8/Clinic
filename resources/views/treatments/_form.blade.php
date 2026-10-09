@php
    use App\Support\ClinicTime;
    use App\Support\LocalizedDecimal;

    $user = auth()->user();

    // Linhas já salvas mais algumas em branco, sempre dentro do limite aceito.
    $saved = old('products', $treatment->exists
        ? $treatment->products->map(fn ($product) => [
            'product_batch_id' => $product->product_batch_id,
            'quantity' => LocalizedDecimal::quantity($product->quantity),
        ])->all()
        : []);
    $rows = min(App\Http\Requests\TreatmentRequest::MAX_PRODUCTS, max(3, count($saved) + 2));
    $batchesByProduct = $batches->groupBy('product_id');

    // No Início o formulário abre em um diálogo ($scope é o id dele) e o
    // paciente é escolhido no próprio formulário, em vez de vir do prontuário.
    $scope ??= null;
    $section = $scope ? 'border-t border-slate-200 pt-5' : 'card p-6';
@endphp
@csrf

@if ($appointment ?? null)
    <input type="hidden" name="appointment_id" value="{{ $appointment->id }}">
    <div class="rounded-lg border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-900" role="note">
        Atendimento do agendamento de {{ ClinicTime::format($appointment->scheduled_at, 'd/m/Y') }}
        às {{ ClinicTime::format($appointment->scheduled_at, 'H:i') }}.
        O agendamento passa para "Atendido" quando o atendimento for finalizado.
    </div>
@endif
@error('appointment_id')
    <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900" role="alert">{{ $message }}</div>
@enderror

@if ($scope)
    <input type="hidden" name="_dialog" value="{{ $scope }}">
    <x-patient-picker :scope="$scope" :patient="$patient" autofocus />
    {{-- Avisos de saúde do paciente escolhido, buscados e preenchidos por app.js. --}}
    <div hidden data-health-alerts data-url="{{ route('patients.health-alerts', ['patient' => '__ID__']) }}" role="note" aria-live="polite"
         class="rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900">
        <p class="font-semibold">Atenção</p>
        <ul class="mt-1 space-y-1" data-health-alerts-list></ul>
    </div>
@else
    <x-health-alerts :anamnesis="$patient->anamnesis" />
@endif

<section class="{{ $section }}">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <h2 class="text-sm font-semibold tracking-wide text-slate-500 uppercase">Atendimento</h2>
        @unless ($scope)
            @can('updateClinicalRecords', $patient)
                <a href="{{ route('patients.anamnesis.edit', $patient) }}" class="text-sm text-teal-800 hover:underline" target="_blank" rel="noopener">
                    {{ $patient->anamnesis ? 'Atualizar a ficha de saúde (anamnese)' : 'Preencher a ficha de saúde (anamnese)' }}
                </a>
            @endcan
        @endunless
    </div>
    <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-6">
        <x-form.input :scope="$scope" class="md:col-span-2" name="performed_at" label="Data e hora" type="datetime-local" required
                      :value="ClinicTime::toInput($treatment->performed_at ?? now())"
                      :max="ClinicTime::toInput(now()->addMinutes(5))" />

        @if ($user->isAdmin())
            <x-form.select :scope="$scope" class="md:col-span-4" name="professional_id" label="Profissional responsável" required
                           :options="$professionals->pluck('name', 'id')->all()" :selected="$treatment->professional_id ?? $user->id" />
        @else
            <div class="md:col-span-4">
                <p class="label">Profissional responsável</p>
                <p class="py-2 text-sm text-slate-700">{{ $treatment->exists ? $treatment->professional->name : $user->name }}</p>
            </div>
        @endif

        <x-form.select :scope="$scope" class="md:col-span-3" name="procedure_id" label="Procedimento" required placeholder="Selecione"
                       :options="$procedures->pluck('name', 'id')->all()" :selected="$treatment->procedure_id" />
        <x-form.input :scope="$scope" class="md:col-span-3" name="treated_areas" label="Áreas tratadas" :value="$treatment->treated_areas" maxlength="255"
                      autocomplete="off" placeholder="Ex.: Glabela, frontal e periorbital" />

        <x-form.textarea :scope="$scope" class="md:col-span-6" name="assessment" label="Avaliação" :value="$treatment->assessment" rows="3" maxlength="5000"
                         hint="Queixa, exame da pele, indicação e plano. Gravado com criptografia." />
        <x-form.textarea :scope="$scope" class="md:col-span-6" name="description" label="Descrição do procedimento" :value="$treatment->description" required rows="5" maxlength="10000"
                         hint="Técnica, pontos de aplicação, unidades ou volume por região, anestesia e evolução. Gravado com criptografia." />
    </div>
</section>

<section class="{{ $section }}">
    <h2 class="text-sm font-semibold tracking-wide text-slate-500 uppercase">Produtos utilizados</h2>
    <p class="mt-1 text-sm text-slate-600">
        Escolha o lote aplicado e a quantidade. A baixa no estoque acontece ao finalizar o atendimento.
        @unless ($treatment->exists)
            Se deixar em branco, o sistema sugere os produtos padrão do procedimento.
        @endunless
    </p>
    @error('products')
        <p class="mt-2 text-sm text-red-700">{{ $message }}</p>
    @enderror

    @if ($batches->isEmpty())
        <p class="mt-4 text-sm text-slate-600">Nenhum lote com saldo e dentro da validade no estoque.</p>
    @else
        <div class="mt-4 space-y-3">
            @for ($i = 0; $i < $rows; $i++)
                @php $row = $saved[$i] ?? []; @endphp
                <fieldset class="grid grid-cols-3 gap-3">
                    <legend class="sr-only">Produto {{ $i + 1 }}</legend>
                    <div class="col-span-2">
                        <label for="products-{{ $i }}-batch" class="label @if ($i > 0) sr-only @endif">Produto e lote</label>
                        <select id="products-{{ $i }}-batch" name="products[{{ $i }}][product_batch_id]"
                                @class(['input', 'input-error' => $errors->has("products.$i.product_batch_id")])>
                            <option value="">—</option>
                            @foreach ($batchesByProduct as $productBatches)
                                @php $product = $productBatches->first()->product; @endphp
                                <optgroup label="{{ $product->name }}">
                                    @foreach ($productBatches as $batch)
                                        <option value="{{ $batch->id }}" @selected((string) ($row['product_batch_id'] ?? '') === (string) $batch->id)>
                                            {{ $product->name }} · lote {{ $batch->batch_code }}
                                            · {{ $batch->expires_on ? 'val. '.$batch->expires_on->format('d/m/Y') : 'sem validade' }}
                                            · saldo {{ LocalizedDecimal::quantity($batch->quantity) }} {{ $product->unit->symbol() }}
                                        </option>
                                    @endforeach
                                </optgroup>
                            @endforeach
                        </select>
                        @error("products.$i.product_batch_id")
                            <p class="mt-1 text-sm text-red-700">{{ $message }}</p>
                        @enderror
                    </div>
                    <div>
                        <label for="products-{{ $i }}-quantity" class="label @if ($i > 0) sr-only @endif">Quantidade</label>
                        <input id="products-{{ $i }}-quantity" name="products[{{ $i }}][quantity]" type="text" inputmode="decimal" autocomplete="off"
                               value="{{ $row['quantity'] ?? '' }}" @class(['input', 'input-error' => $errors->has("products.$i.quantity")])>
                        @error("products.$i.quantity")
                            <p class="mt-1 text-sm text-red-700">{{ $message }}</p>
                        @enderror
                    </div>
                </fieldset>
            @endfor
        </div>
    @endif
</section>

<section class="{{ $section }}">
    <h2 class="text-sm font-semibold tracking-wide text-slate-500 uppercase">Pós-procedimento</h2>
    <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-6">
        <x-form.textarea :scope="$scope" class="md:col-span-6" name="post_care" label="Orientações dadas ao paciente" :value="$treatment->post_care" rows="3" maxlength="5000" />
        <x-form.textarea :scope="$scope" class="md:col-span-6" name="complications" label="Intercorrências" :value="$treatment->complications" rows="2" maxlength="5000"
                         hint="Reações, hematomas, queixas. Deixe em branco se não houve." />
        <x-form.input :scope="$scope" class="md:col-span-2" name="return_date" label="Retorno previsto" type="date" :value="$treatment->return_date?->format('Y-m-d')" />
    </div>
</section>

<div class="flex flex-wrap items-center justify-end gap-3">
    @if ($scope)
        <button type="button" class="btn btn-secondary" data-dialog-close>Cancelar</button>
    @else
        <a href="{{ $treatment->exists ? route('patients.treatments.show', [$patient, $treatment]) : route('patients.show', $patient) }}" class="btn btn-secondary">Cancelar</a>
    @endif
    <button type="submit" class="btn btn-primary">{{ $treatment->exists ? 'Salvar alterações' : 'Salvar atendimento' }}</button>
</div>
