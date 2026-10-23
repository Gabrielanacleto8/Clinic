<x-layouts.app :title="'Ficha de saúde · '.$patient->name">
    <div class="mx-auto max-w-3xl">
        <a href="{{ route('patients.show', $patient) }}" class="text-sm text-slate-600 hover:underline">← {{ $patient->name }}</a>
        <h1 class="mt-1 text-xl font-semibold tracking-tight">Ficha de saúde</h1>
        <p class="mt-1 text-sm text-slate-600">Informações consultadas antes de cada procedimento. Todos os campos são gravados com criptografia.</p>

        <form method="POST" action="{{ route('patients.anamnesis.update', $patient) }}" class="mt-6 space-y-6" novalidate data-keepalive="{{ route('session.keepalive') }}">
            @csrf
            @method('PUT')

            <section class="card space-y-4 p-6">
                <x-form.textarea name="allergies" label="Alergias" :value="$anamnesis->allergies" rows="2" maxlength="2000"
                                 hint="Medicamentos, anestésicos, látex, cosméticos, alimentos. Aparece em destaque no prontuário." />

                <div>
                    <label class="flex items-center gap-2 text-sm">
                        <input type="hidden" name="pregnant_or_lactating" value="0">
                        <input type="checkbox" name="pregnant_or_lactating" value="1" class="size-4 rounded border-slate-300 accent-teal-700"
                               @checked(old('pregnant_or_lactating', $anamnesis->pregnant_or_lactating))>
                        Gestante ou lactante
                    </label>
                </div>

                <x-form.textarea name="medications" label="Medicamentos em uso" :value="$anamnesis->medications" rows="2" maxlength="2000"
                                 hint="Inclua anticoagulantes, isotretinoína, corticoides e suplementos." />
                <x-form.textarea name="health_conditions" label="Condições de saúde" :value="$anamnesis->health_conditions" rows="3" maxlength="2000"
                                 hint="Diabetes, doenças autoimunes, herpes, tendência a queloide, distúrbios de coagulação, entre outras." />
                <x-form.textarea name="previous_procedures" label="Procedimentos estéticos anteriores" :value="$anamnesis->previous_procedures" rows="3" maxlength="2000"
                                 hint="O que foi feito, quando, com qual produto e se houve alguma reação." />
                <x-form.textarea name="notes" label="Observações" :value="$anamnesis->notes" rows="3" maxlength="5000" />
            </section>

            <div class="flex flex-wrap items-center justify-end gap-3">
                <a href="{{ route('patients.show', $patient) }}" class="btn btn-secondary">Cancelar</a>
                <button type="submit" class="btn btn-primary">Salvar ficha</button>
            </div>
        </form>
    </div>
</x-layouts.app>
