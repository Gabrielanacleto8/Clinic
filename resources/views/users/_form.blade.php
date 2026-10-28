@php
    $isSelf = $user->exists && $user->is(auth()->user());
@endphp
@csrf

<section class="card space-y-4 p-6">
    <x-form.input name="name" label="Nome completo" :value="$user->name" required maxlength="255" autocomplete="off" />
    <x-form.input name="email" label="E-mail" type="email" :value="$user->email" required maxlength="255" autocomplete="off" />
    <x-form.input name="professional_registration" label="Registro profissional" :value="$user->professional_registration" maxlength="40" autocomplete="off"
                  hint="Conselho e número, como CRBM-1 12345. Sai junto do nome no prontuário impresso e nos termos. Deixe em branco para quem não atende." />

    @if ($isSelf)
        {{-- A própria conta não pode ser rebaixada nem desativada. --}}
        <input type="hidden" name="role" value="{{ $user->role->value }}">
        <input type="hidden" name="is_active" value="1">
        <div>
            <p class="label">Perfil</p>
            <p class="text-sm text-slate-600">{{ $user->role->label() }} · você não pode alterar o próprio perfil nem desativar a própria conta.</p>
        </div>
    @else
        <x-form.select name="role" label="Perfil" :options="App\Enums\UserRole::options()"
                       :selected="$user->role?->value ?? App\Enums\UserRole::Receptionist->value" required />

        @if ($user->exists)
            <div>
                <label class="flex items-center gap-2 text-sm">
                    <input type="hidden" name="is_active" value="0">
                    <input type="checkbox" name="is_active" value="1" class="size-4 rounded border-slate-300 accent-brand"
                           @checked(old('is_active', $user->is_active))>
                    Conta ativa
                </label>
                <p class="mt-1 text-xs text-slate-500">Contas inativas não conseguem entrar e têm a sessão encerrada.</p>
                @error('is_active')
                    <p class="mt-1 text-sm text-red-700">{{ $message }}</p>
                @enderror
            </div>
        @endif
    @endif
</section>

@unless ($isSelf)
    <section class="card space-y-4 p-6">
        <div>
            <h2 class="text-sm font-semibold tracking-wide text-slate-500 uppercase">
                {{ $user->exists ? 'Redefinir senha' : 'Senha provisória' }}
            </h2>
            <p class="mt-1 text-sm text-slate-600">
                @if ($user->exists)
                    Preencha apenas para redefinir. O usuário será desconectado e terá de criar uma nova senha ao entrar.
                @else
                    O usuário terá de criar a própria senha no primeiro acesso.
                @endif
                Mínimo de 8 caracteres, com maiúsculas, minúsculas e números.
            </p>
        </div>
        <x-form.input name="password" label="Senha provisória" type="password" :required="! $user->exists" autocomplete="new-password" />
        <x-form.input name="password_confirmation" label="Confirme a senha provisória" type="password" :required="! $user->exists" autocomplete="new-password" />
    </section>
@endunless

<div class="flex flex-wrap items-center justify-end gap-3">
    <a href="{{ route('users.index') }}" class="btn btn-secondary">Cancelar</a>
    <button type="submit" class="btn btn-primary">{{ $user->exists ? 'Salvar alterações' : 'Criar usuário' }}</button>
</div>
