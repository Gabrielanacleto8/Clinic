<x-layouts.app title="Usuários">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold tracking-tight">Usuários</h1>
            <p class="mt-1 text-sm text-slate-600">Quem pode acessar o sistema e com qual perfil.</p>
        </div>
        <a href="{{ route('users.create') }}" class="btn btn-primary">Novo usuário</a>
    </div>

    <div class="card mt-6 overflow-x-auto">
        <table class="w-full min-w-2xl text-left text-sm">
            <thead class="border-b border-slate-200 bg-slate-50 text-xs tracking-wide text-slate-500 uppercase">
                <tr>
                    <th scope="col" class="px-4 py-3 font-medium">Nome</th>
                    <th scope="col" class="px-4 py-3 font-medium">E-mail</th>
                    <th scope="col" class="px-4 py-3 font-medium">Perfil</th>
                    <th scope="col" class="px-4 py-3 font-medium">Situação</th>
                    <th scope="col" class="px-4 py-3"><span class="sr-only">Ações</span></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @foreach ($users as $user)
                    <tr class="hover:bg-slate-50">
                        <td class="px-4 py-3 font-medium">
                            {{ $user->name }}
                            @if ($user->is(auth()->user()))
                                <span class="font-normal text-slate-400">(você)</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-slate-600">{{ $user->email }}</td>
                        <td class="px-4 py-3 text-slate-600">{{ $user->role->label() }}</td>
                        <td class="px-4 py-3 whitespace-nowrap">
                            @if (! $user->is_active)
                                <span class="badge bg-slate-200 text-slate-700">Inativo</span>
                            @elseif ($user->mustChangePassword())
                                <span class="badge bg-amber-100 text-amber-800">Senha provisória</span>
                            @else
                                <span class="badge bg-emerald-100 text-emerald-800">Ativo</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right">
                            <a href="{{ route('users.edit', $user) }}" class="font-medium text-teal-800 hover:underline">Editar</a>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $users->links() }}
    </div>
</x-layouts.app>
