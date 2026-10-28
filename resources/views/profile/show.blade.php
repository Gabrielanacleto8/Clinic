@php
    use App\Support\ClinicTime;
    use App\Support\DeviceName;

    $words = preg_split('/\s+/', trim($user->name)) ?: [];
    $initials = mb_strtoupper(mb_substr($words[0] ?? '', 0, 1).(count($words) > 1 ? mb_substr(end($words), 0, 1) : ''));
    $heading = 'text-sm font-semibold tracking-wide text-slate-500 uppercase';
@endphp
<x-layouts.app title="Meu perfil">
    <div class="mx-auto max-w-3xl space-y-6">
        <section class="card flex flex-wrap items-center gap-4 p-6" aria-label="Seus dados">
            <span class="flex size-16 shrink-0 items-center justify-center rounded-2xl bg-teal-100 text-xl font-semibold text-teal-800" aria-hidden="true">{{ $initials }}</span>
            <div class="min-w-0 flex-1">
                <h1 class="text-xl font-semibold tracking-tight">{{ $user->name }}</h1>
                <p class="mt-0.5 text-sm break-all text-slate-600">{{ $user->email }}</p>
                <p class="mt-2 flex flex-wrap gap-2">
                    <span class="badge bg-teal-50 text-teal-800">{{ $user->role->label() }}</span>
                    @if ($user->professional_registration)
                        <span class="badge bg-slate-100 text-slate-700">{{ $user->professional_registration }}</span>
                    @endif
                </p>
            </div>
            <dl class="grid w-full grid-cols-1 gap-3 border-t border-slate-100 pt-4 text-sm sm:grid-cols-2">
                <div>
                    <dt class="text-xs text-slate-500">Conta criada em</dt>
                    <dd>{{ ClinicTime::format($user->created_at, 'd/m/Y') }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-slate-500">Senha alterada em</dt>
                    <dd>{{ $user->password_changed_at ? ClinicTime::format($user->password_changed_at) : '—' }}</dd>
                </div>
            </dl>
            <p class="w-full text-xs text-slate-500">Nome, e-mail, perfil e registro profissional são alterados pelo administrador, em Usuários.</p>
        </section>

        <section class="card p-6" aria-labelledby="aparencia">
            <h2 id="aparencia" class="{{ $heading }}">Aparência</h2>
            <p class="mt-1 text-sm text-slate-600">Vale para este aparelho. O botão de lua no topo troca na hora.</p>
            <form method="POST" action="{{ route('profile.theme') }}" class="mt-4 flex flex-wrap items-end gap-3">
                @csrf
                <fieldset class="flex flex-wrap gap-2">
                    <legend class="sr-only">Tema</legend>
                    @foreach (App\Support\Theme::OPTIONS as $value => $label)
                        <label class="flex cursor-pointer items-center gap-2 rounded-lg border border-slate-200 px-3 py-2 text-sm has-checked:border-teal-600 has-checked:bg-teal-50">
                            <input type="radio" name="theme" value="{{ $value }}" class="accent-brand" @checked($theme === $value)>
                            {{ $label }}
                        </label>
                    @endforeach
                </fieldset>
                <button type="submit" class="btn btn-secondary">Aplicar</button>
            </form>
        </section>

        <section class="card p-6" aria-labelledby="senha">
            <h2 id="senha" class="{{ $heading }}">Alterar senha</h2>
            <p class="mt-1 text-sm text-slate-600">Mínimo de 8 caracteres, com letras maiúsculas e minúsculas e números. Ao trocar, as sessões abertas em outros aparelhos são encerradas.</p>
            <form method="POST" action="{{ route('password.update') }}" class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2" novalidate>
                @csrf
                @method('PUT')
                <input type="hidden" name="origem" value="perfil">
                <x-form.input class="sm:col-span-2" name="current_password" label="Senha atual" type="password" required autocomplete="current-password" />
                <x-form.input name="password" label="Nova senha" type="password" required autocomplete="new-password" />
                <x-form.input name="password_confirmation" label="Confirme a nova senha" type="password" required autocomplete="new-password" />
                <div class="flex justify-end sm:col-span-2">
                    <button type="submit" class="btn btn-primary">Salvar nova senha</button>
                </div>
            </form>
        </section>

        <section class="card p-6" aria-labelledby="acessos">
            <h2 id="acessos" class="{{ $heading }}">Acessos recentes</h2>
            <p class="mt-1 text-sm text-slate-600">Se algum não foi você, troque a senha e avise o administrador.</p>
            @if ($logins->isEmpty())
                <p class="mt-4 text-sm text-slate-500">Nenhum acesso registrado.</p>
            @else
                <ul class="mt-3 divide-y divide-slate-100 text-sm">
                    @foreach ($logins as $login)
                        <li class="flex flex-wrap justify-between gap-x-4 gap-y-0.5 py-2">
                            <span>{{ DeviceName::from($login->user_agent) }}</span>
                            <span class="text-slate-500 tabular-nums">{{ ClinicTime::format($login->created_at) }} · IP {{ $login->ip_address }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        <section class="card p-6" aria-labelledby="permissoes">
            <h2 id="permissoes" class="{{ $heading }}">O que seu perfil pode fazer</h2>
            @if ($user->isAdmin())
                <p class="mt-2 text-sm text-slate-700">Administrador: acesso total ao sistema, inclusive usuários, permissões, auditoria e dados da clínica.</p>
            @elseif ($granted->isEmpty())
                <p class="mt-2 text-sm text-slate-700">Nenhuma permissão concedida. Fale com o administrador.</p>
            @else
                <dl class="mt-3 grid grid-cols-1 gap-4 sm:grid-cols-2">
                    @foreach ($granted as $module => $permissions)
                        <div>
                            <dt class="text-xs font-medium text-slate-500">{{ $module }}</dt>
                            <dd>
                                <ul class="mt-1 list-disc space-y-0.5 pl-5 text-sm text-slate-700">
                                    @foreach ($permissions as $permission)
                                        <li>{{ $permission->label() }}</li>
                                    @endforeach
                                </ul>
                            </dd>
                        </div>
                    @endforeach
                </dl>
            @endif
        </section>
    </div>
</x-layouts.app>
