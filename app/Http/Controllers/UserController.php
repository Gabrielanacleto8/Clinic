<?php

namespace App\Http\Controllers;

use App\Enums\AuditAction;
use App\Enums\UserRole;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class UserController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): View
    {
        Gate::authorize('viewAny', User::class);

        return view('users.index', ['users' => User::query()->orderBy('name')->paginate(20)]);
    }

    public function create(): View
    {
        Gate::authorize('create', User::class);

        return view('users.create', ['user' => new User]);
    }

    public function store(StoreUserRequest $request): RedirectResponse
    {
        DB::transaction(function () use ($request): void {
            $user = new User($request->safe()->only(['name', 'email', 'password']));
            $user->role = UserRole::from($request->validated('role'));
            $user->is_active = true;
            // Senha definida pelo administrador é provisória.
            $user->password_changed_at = null;
            $user->save();

            $this->audit->log(AuditAction::UserCreated, $user, ['role' => $user->role->value]);
        });

        return redirect()->route('users.index')
            ->with('success', 'Usuário criado. Ele deverá definir uma nova senha no primeiro acesso.');
    }

    public function edit(User $user): View
    {
        Gate::authorize('update', $user);

        return view('users.edit', ['user' => $user]);
    }

    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        DB::transaction(function () use ($request, $user): void {
            $user->fill($request->safe()->only(['name', 'email']));
            $user->role = UserRole::from($request->validated('role'));
            $user->is_active = $request->boolean('is_active');

            $changed = array_keys($user->getDirty());

            if ($changed !== []) {
                $this->audit->log(AuditAction::UserUpdated, $user, ['fields' => $changed]);
            }

            if ($request->filled('password')) {
                // Nova senha provisória: força a troca e, via auth.session,
                // derruba as sessões abertas desse usuário.
                $user->password = $request->validated('password');
                $user->password_changed_at = null;

                $this->audit->log(AuditAction::UserPasswordReset, $user);
            }

            $user->save();
        });

        return redirect()->route('users.index')->with('success', 'Usuário atualizado.');
    }
}
