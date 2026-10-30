@php
    use App\Support\Cnpj;
    use App\Support\Phone;
@endphp
<x-layouts.app title="Dados da clínica">
    <div class="mx-auto max-w-2xl">
        <h1 class="text-xl font-semibold tracking-tight">Dados da clínica</h1>
        <p class="mt-1 text-sm text-slate-600">Identificação impressa no prontuário, nos termos de consentimento e nos orçamentos. O nome exibido no menu é {{ config('app.name') }}, definido na instalação.</p>

        <form method="POST" action="{{ route('clinic.update') }}" class="mt-6 space-y-6" novalidate>
            @csrf
            @method('PUT')

            <section class="card grid grid-cols-1 gap-4 p-6 sm:grid-cols-6">
                <x-form.input class="sm:col-span-6" name="legal_name" label="Razão social" :value="$profile->legal_name" maxlength="150" autocomplete="organization" />
                <x-form.input class="sm:col-span-3" name="cnpj" label="CNPJ" :value="$profile->cnpj ? Cnpj::format($profile->cnpj) : null"
                              inputmode="numeric" maxlength="18" placeholder="00.000.000/0000-00" data-mask="cnpj" autocomplete="off" />
                <x-form.input class="sm:col-span-3" name="phone" label="Telefone" :value="$profile->phone ? Phone::format($profile->phone) : null"
                              inputmode="numeric" maxlength="15" placeholder="(00) 00000-0000" data-mask="phone" autocomplete="tel" />
                <x-form.input class="sm:col-span-6" name="address" label="Endereço" :value="$profile->address" maxlength="255"
                              hint="Em uma linha, como sai no documento: rua, número, bairro, cidade/UF e CEP." autocomplete="street-address" />
                <x-form.input class="sm:col-span-6" name="email" label="E-mail" type="email" :value="$profile->email" maxlength="150" autocomplete="email" />
                <x-form.input class="sm:col-span-6" name="technical_lead" label="Responsável técnico" :value="$profile->technical_lead" maxlength="150"
                              hint="Nome e registro no conselho, como Dra. Ana Lima · CRBM-1 12345." />
            </section>

            <div class="flex justify-end">
                <button type="submit" class="btn btn-primary">Salvar dados</button>
            </div>
        </form>

        <section class="card mt-8 p-6" aria-labelledby="logo-titulo">
            <h2 id="logo-titulo" class="text-sm font-semibold tracking-wide text-slate-500 uppercase">Logo</h2>
            <p class="mt-1 text-sm text-slate-600">Aparece no menu, na tela de entrada, nos documentos impressos e como ícone do aplicativo instalado no celular.</p>

            <div class="mt-4 flex flex-wrap items-center gap-4">
                @if ($logoVersion)
                    <img src="{{ route('clinic.logo', ['v' => $logoVersion]) }}" alt="Logo atual" class="size-20 rounded-lg border border-slate-200 bg-white object-contain p-1">
                @else
                    <p class="text-sm text-slate-500">Nenhum logo enviado.</p>
                @endif
            </div>

            <form method="POST" action="{{ route('clinic.logo.update') }}" enctype="multipart/form-data" class="mt-4 space-y-3" novalidate>
                @csrf
                <div>
                    <label for="logo" class="label">{{ $logoVersion ? 'Trocar o logo' : 'Enviar logo' }}</label>
                    <input id="logo" name="logo" type="file" required accept="image/jpeg,image/png,image/webp"
                           @class(['input file:mr-3 file:cursor-pointer file:rounded-md file:border-0 file:bg-slate-100 file:px-3 file:py-1 file:text-sm file:font-medium', 'input-error' => $errors->has('logo')])>
                    <p class="mt-1 text-xs text-slate-500">JPEG, PNG ou WebP. Fundo transparente vira branco. Prefira um logo quadrado.</p>
                    @error('logo')
                        <p class="mt-1 text-sm text-red-700">{{ $message }}</p>
                    @enderror
                </div>
                <button type="submit" class="btn btn-secondary">Enviar</button>
            </form>

            @if ($logoVersion)
                <form method="POST" action="{{ route('clinic.logo.destroy') }}" class="mt-4 border-t border-slate-200 pt-4" data-confirm="Remover o logo da clínica?">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="cursor-pointer text-sm font-medium text-red-700 hover:underline">Remover logo</button>
                </form>
            @endif
        </section>
    </div>
</x-layouts.app>
