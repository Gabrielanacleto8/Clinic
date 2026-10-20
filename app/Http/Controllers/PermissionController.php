<?php

namespace App\Http\Controllers;

use App\Enums\AuditAction;
use App\Enums\Permission;
use App\Enums\UserRole;
use App\Http\Requests\UpdatePermissionsRequest;
use App\Services\AuditLogger;
use App\Services\RolePermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Aba Permissões do módulo de usuários: o que cada perfil acessa.
 */
class PermissionController extends Controller
{
    public function edit(RolePermissions $rolePermissions): View
    {
        Gate::authorize('manage-permissions');

        return view('users.permissions', [
            'modules' => collect(Permission::cases())->groupBy(fn (Permission $permission) => $permission->module()),
            'roles' => UserRole::configurable(),
            'rolePermissions' => $rolePermissions,
        ]);
    }

    public function update(UpdatePermissionsRequest $request, RolePermissions $rolePermissions, AuditLogger $audit): RedirectResponse
    {
        DB::transaction(function () use ($request, $rolePermissions, $audit): void {
            foreach (UserRole::configurable() as $role) {
                $changes = $rolePermissions->sync($role, $request->permissionsFor($role));

                if ($changes['added'] !== [] || $changes['removed'] !== []) {
                    $audit->log(AuditAction::PermissionsUpdated, null, ['role' => $role->value, ...$changes]);
                }
            }
        });

        return redirect()->route('users.permissions.edit')->with('success', 'Permissões atualizadas.');
    }
}
