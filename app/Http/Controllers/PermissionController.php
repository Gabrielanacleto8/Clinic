<?php

namespace App\Http\Controllers;

use App\Enums\AuditAction;
use App\Enums\Permission;
use App\Enums\UserRole;
use App\Http\Requests\UpdatePermissionsRequest;
use App\Services\AuditLogger;
use App\Services\DiscountLimits;
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
    public function edit(RolePermissions $rolePermissions, DiscountLimits $discountLimits, AuditLogger $audit): View
    {
        Gate::authorize('manage-permissions');

        $audit->log(AuditAction::PermissionsViewed);

        return view('users.permissions', [
            'modules' => collect(Permission::cases())->groupBy(fn (Permission $permission) => $permission->module()),
            'roles' => UserRole::configurable(),
            'rolePermissions' => $rolePermissions,
            'discountLimits' => $discountLimits,
        ]);
    }

    public function update(UpdatePermissionsRequest $request, RolePermissions $rolePermissions, DiscountLimits $discountLimits, AuditLogger $audit): RedirectResponse
    {
        $implied = [];

        DB::transaction(function () use ($request, $rolePermissions, $discountLimits, $audit, &$implied): void {
            $limits = $discountLimits->sync($request->discountLimits());

            if ($limits !== []) {
                $audit->log(AuditAction::DiscountLimitsUpdated, null, ['limits' => array_map(fn (array $change) => $change['from'].' → '.$change['to'], $limits)]);
            }

            foreach (UserRole::configurable() as $role) {
                $requested = $request->permissionsFor($role);
                $changes = $rolePermissions->sync($role, $requested);

                if ($changes['added'] !== [] || $changes['removed'] !== []) {
                    $audit->log(AuditAction::PermissionsUpdated, null, ['role' => $role->value, ...$changes]);
                }

                // O que entrou por dependência, sem a caixa marcada, é dito na mensagem.
                $requestedValues = array_map(fn (Permission $permission) => $permission->value, $requested);
                $extra = array_values(array_diff($changes['added'], $requestedValues));

                if ($extra !== []) {
                    $implied[] = $role->label().': '.implode(', ', array_map(fn (string $value) => Permission::from($value)->label(), $extra));
                }
            }
        });

        $message = 'Permissões atualizadas.';

        if ($implied !== []) {
            $message .= ' Incluídas automaticamente por dependência: '.implode('; ', $implied).'.';
        }

        return redirect()->route('users.permissions.edit')->with('success', $message);
    }
}
