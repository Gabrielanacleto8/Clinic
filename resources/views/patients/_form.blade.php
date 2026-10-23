@php
    // CPF já cadastrado: o caminho para o cadastro existente vem junto com o erro.
    $cpfOwner = session('cpf_owner');
    $cpfOwnerPatient = is_array($cpfOwner) ? App\Models\Patient::withTrashed()->find($cpfOwner['id'], ['id', 'deleted_at']) : null;
    // Antes do cadastro, a idade só se sabe pela data digitada.
    $minor = $patient->exists ? $patient->isMinor() : false;
@endphp
@csrf

<section class="card p-6">
    <h2 class="text-sm font-semibold tracking-wide text-slate-500 uppercase">Identificação</h2>
    <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-6">
        <x-form.input class="md:col-span-4" name="name" label="Nome completo" :value="$patient->name" required maxlength="150" autocomplete="off" />
        <div class="md:col-span-2">
            <x-form.input name="cpf" label="CPF" :value="$patient->exists ? $patient->formattedCpf() : null"
                          required inputmode="numeric" maxlength="14" placeholder="000.000.000-00" data-mask="cpf" autocomplete="off" />
            @if ($cpfOwnerPatient)
                <p class="mt-1 text-sm">
                    @if ($cpfOwnerPatient->trashed())
                        @can('restore', $cpfOwnerPatient)
                            <a href="{{ route('patients.record', $cpfOwnerPatient) }}" class="text-teal-800 hover:underline">Ver o prontuário</a>
                            ·
                            <form method="POST" action="{{ route('patients.restore', $cpfOwnerPatient) }}" class="inline" data-confirm="Restaurar o cadastro arquivado com este CPF?">
                                @csrf
                                @method('PATCH')
                                <button type="submit" class="cursor-pointer font-medium text-teal-800 hover:underline">Restaurar o cadastro</button>
                            </form>
                        @else
                            <span class="text-slate-600">Peça ao administrador para restaurar o cadastro arquivado.</span>
                        @endcan
                    @else
                        <a href="{{ route('patients.show', $cpfOwnerPatient) }}" class="font-medium text-teal-800 hover:underline">Abrir o cadastro existente</a>
                    @endif
                </p>
            @endif
        </div>
        <x-form.input class="md:col-span-2" name="birth_date" label="Data de nascimento" type="date"
                      :value="$patient->birth_date?->format('Y-m-d')" required min="1900-01-02" :max="now()->format('Y-m-d')" />
        <x-form.select class="md:col-span-2" name="sex" label="Sexo" :options="App\Enums\Sex::options()"
                       :selected="$patient->sex?->value" placeholder="Não informado" />
    </div>
</section>

<section class="card p-6">
    <h2 class="text-sm font-semibold tracking-wide text-slate-500 uppercase">Contato</h2>
    <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-6">
        <x-form.input class="md:col-span-2" name="phone" label="Telefone" type="tel" :value="$patient->exists ? $patient->formattedPhone() : null"
                      inputmode="numeric" maxlength="15" placeholder="(00) 00000-0000" data-mask="phone" autocomplete="off" />
        <x-form.input class="md:col-span-4" name="email" label="E-mail" type="email" :value="$patient->email" maxlength="255" autocomplete="off" />
    </div>
</section>

<section class="card p-6">
    <h2 class="text-sm font-semibold tracking-wide text-slate-500 uppercase">Responsável legal</h2>
    <p class="mt-1 text-sm text-slate-600">Obrigatório para menor de 18 anos: é quem autoriza o procedimento e o uso de imagem, e quem a clínica contata.</p>
    <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-6">
        <x-form.input class="md:col-span-3" name="guardian_name" label="Nome do responsável" :value="$patient->guardian_name" :required="$minor" maxlength="150" autocomplete="off" />
        <x-form.input class="md:col-span-1" name="guardian_relationship" label="Parentesco" :value="$patient->guardian_relationship" :required="$minor" maxlength="50" autocomplete="off" placeholder="Mãe, pai, tutor" />
        <x-form.input class="md:col-span-2" name="guardian_cpf" label="CPF do responsável" :value="$patient->guardian_cpf ? App\Support\Cpf::format($patient->guardian_cpf) : null"
                      inputmode="numeric" maxlength="14" placeholder="000.000.000-00" data-mask="cpf" autocomplete="off" hint="Gravado com criptografia." />
        <x-form.input class="md:col-span-2" name="guardian_phone" label="Telefone do responsável" type="tel" :value="$patient->guardian_phone ? $patient->formattedGuardianPhone() : null"
                      :required="$minor" inputmode="numeric" maxlength="15" placeholder="(00) 00000-0000" data-mask="phone" autocomplete="off" />
    </div>
</section>

<section class="card p-6">
    <h2 class="text-sm font-semibold tracking-wide text-slate-500 uppercase">Endereço</h2>
    <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-6">
        {{-- Digitar o CEP preenche o endereço (app.js, data-cep-lookup); o que já está preenchido não é sobrescrito. --}}
        <x-form.input class="md:col-span-2" name="zip_code" label="CEP" :value="$patient->exists ? $patient->formattedZipCode() : null"
                      inputmode="numeric" maxlength="9" placeholder="00000-000" data-mask="cep" autocomplete="off"
                      :data-cep-lookup="route('cep.lookup', ['cep' => '__CEP__'])" />
        <x-form.input class="md:col-span-3" name="street" label="Logradouro" :value="$patient->street" maxlength="150" autocomplete="off" />
        <x-form.input class="md:col-span-1" name="number" label="Número" :value="$patient->number" maxlength="20" autocomplete="off" />
        <x-form.input class="md:col-span-2" name="complement" label="Complemento" :value="$patient->complement" maxlength="100" autocomplete="off" />
        <x-form.input class="md:col-span-2" name="district" label="Bairro" :value="$patient->district" maxlength="100" autocomplete="off" />
        <x-form.input class="md:col-span-1" name="city" label="Cidade" :value="$patient->city" maxlength="100" autocomplete="off" />
        <x-form.select class="md:col-span-1" name="state" label="UF" :options="App\Support\BrazilianStates::options()"
                       :selected="$patient->state" placeholder="—" />
    </div>
</section>

<section class="card p-6">
    <h2 class="text-sm font-semibold tracking-wide text-slate-500 uppercase">Uso de imagem</h2>
    <label class="mt-4 flex items-start gap-2 text-sm">
        <input type="hidden" name="image_use_consent" value="0">
        <input type="checkbox" name="image_use_consent" value="1" class="mt-0.5 size-4 rounded border-slate-300 accent-teal-700"
               @checked(old('image_use_consent', $patient->image_use_consented_at !== null))>
        <span>
            {{ $minor ? 'O responsável legal autoriza o uso das fotos do paciente em divulgação (redes sociais, portfólio).' : 'O paciente autoriza o uso das fotos dele em divulgação (redes sociais, portfólio).' }}
            <span class="block text-xs text-slate-500">
                Fotos clínicas no prontuário não dependem desta autorização. Guarde o termo assinado como documento do atendimento.
                @if ($patient->image_use_consented_at)
                    Autorização registrada em {{ App\Support\ClinicTime::format($patient->image_use_consented_at) }}.
                @endif
            </span>
        </span>
    </label>
</section>

<section class="card p-6">
    <h2 class="text-sm font-semibold tracking-wide text-slate-500 uppercase">Observações</h2>
    <div class="mt-4">
        <x-form.textarea name="notes" label="Observações gerais" :value="$patient->notes" rows="4" maxlength="5000"
                         hint="Campo gravado com criptografia. Não substitui o prontuário." />
    </div>
</section>

<div class="flex flex-wrap items-center justify-end gap-3">
    <a href="{{ $patient->exists ? route('patients.show', $patient) : route('patients.index') }}" class="btn btn-secondary">Cancelar</a>
    <button type="submit" class="btn btn-primary">{{ $patient->exists ? 'Salvar alterações' : 'Cadastrar paciente' }}</button>
</div>
