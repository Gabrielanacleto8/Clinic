<x-users.page active="permissoes" subtitle="Defina o que cada perfil acessa. O administrador tem sempre acesso total, incluindo usuários e esta tela.">
    <form method="POST" action="{{ route('users.permissions.update') }}" class="mt-4 space-y-6"
          data-confirm="Aplicar as novas permissões? Elas passam a valer imediatamente para todos os usuários desses perfis.">
        @csrf
        @method('PUT')

        @if ($errors->any())
            <p class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900" role="alert">{{ $errors->first() }}</p>
        @endif

        <div class="card relative overflow-x-auto">
            <table class="table-stack w-full min-w-2xl text-left text-sm">
                <thead class="border-b border-slate-200 bg-slate-50 text-xs tracking-wide text-slate-500 uppercase">
                    <tr>
                        <th scope="col" class="px-4 py-3 font-medium">Permissão</th>
                        <th scope="col" class="px-4 py-3 text-center font-medium">{{ App\Enums\UserRole::Admin->label() }}</th>
                        @foreach ($roles as $role)
                            <th scope="col" class="px-4 py-3 text-center font-medium">{{ $role->label() }}</th>
                        @endforeach
                    </tr>
                </thead>
                @foreach ($modules as $module => $permissions)
                    <tbody class="divide-y divide-slate-100 border-b border-slate-200 last:border-b-0">
                        <tr class="bg-slate-50/60">
                            <th scope="rowgroup" colspan="{{ count($roles) + 2 }}" class="px-4 py-2 text-xs font-semibold text-slate-700">{{ $module }}</th>
                        </tr>
                        @foreach ($permissions as $permission)
                            <tr>
                                <th scope="row" class="px-4 py-3 font-normal">{{ $permission->label() }}</th>
                                <td data-label="{{ App\Enums\UserRole::Admin->label() }}" class="px-4 py-3 text-center">
                                    <input type="checkbox" checked disabled class="size-4 accent-brand max-md:size-6" aria-label="{{ $permission->label() }}: administrador, sempre permitido">
                                </td>
                                @foreach ($roles as $role)
                                    <td data-label="{{ $role->label() }}" class="px-4 py-3 text-center">
                                        {{-- data-requires: o app.js marca e trava as permissões de que esta depende. --}}
                                        <span class="inline-flex flex-col items-center gap-0.5">
                                            <input type="checkbox" name="permissions[{{ $role->value }}][]" value="{{ $permission->value }}"
                                                   class="size-4 cursor-pointer accent-brand max-md:size-6" aria-label="{{ $permission->label() }}: {{ $role->label() }}"
                                                   data-requires="{{ implode(',', array_map(fn ($required) => $required->value, $permission->requires())) }}"
                                                   data-label="{{ $permission->label() }}"
                                                   @checked($rolePermissions->allows($role, $permission))>
                                            <span class="text-[10px] leading-tight text-slate-500" data-requires-note></span>
                                        </span>
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                @endforeach
            </table>
        </div>

        <section class="card p-6" aria-labelledby="desconto-maximo">
            <h2 id="desconto-maximo" class="text-sm font-semibold tracking-wide text-slate-500 uppercase">Desconto máximo</h2>
            <p class="mt-1 text-sm text-slate-600">Quanto cada perfil pode descontar do preço de tabela no atendimento e no orçamento. O administrador não tem limite.</p>
            <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-3">
                @foreach ($roles as $role)
                    <x-form.input name="discount_limits[{{ $role->value }}]" id="discount-limit-{{ $role->value }}" :label="$role->label().' (%)'" inputmode="decimal" autocomplete="off"
                                  :value="number_format($discountLimits->for($role), 2, ',', '')" />
                @endforeach
            </div>
        </section>

        <p class="text-xs text-slate-500">
            Permissões dependentes são concedidas juntas e aparecem marcadas e travadas: quem pode editar também pode ver, anular atendimento inclui o financeiro, e o prontuário exige acesso ao cadastro de pacientes.
        </p>

        <div class="flex justify-end">
            <button type="submit" class="btn btn-primary">Salvar permissões</button>
        </div>
    </form>
</x-users.page>
