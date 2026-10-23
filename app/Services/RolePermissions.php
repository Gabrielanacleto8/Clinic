<?php

namespace App\Services;

use App\Enums\Permission;
use App\Enums\UserRole;
use Illuminate\Support\Facades\DB;

/**
 * Fonte única das permissões por perfil.
 *
 * Registrado como "scoped": a tabela é lida uma única vez por requisição, por
 * mais checagens de permissão que a tela faça.
 */
class RolePermissions
{
    /** @var array<string, array<string, true>>|null */
    private ?array $granted = null;

    public function allows(UserRole $role, Permission $permission): bool
    {
        // O administrador tem acesso total e não depende da tabela.
        if ($role === UserRole::Admin) {
            return true;
        }

        return isset($this->granted()[$role->value][$permission->value]);
    }

    /**
     * @return list<Permission>
     */
    public function for(UserRole $role): array
    {
        return array_values(array_filter(Permission::cases(), fn (Permission $permission) => $this->allows($role, $permission)));
    }

    /**
     * Perfis que têm a permissão, incluindo sempre o administrador.
     *
     * @return list<UserRole>
     */
    public function rolesWith(Permission $permission): array
    {
        return array_values(array_filter(UserRole::cases(), fn (UserRole $role) => $this->allows($role, $permission)));
    }

    /**
     * Substitui as permissões de um perfil e devolve o que mudou.
     *
     * @param  list<Permission>  $permissions
     * @return array{added: list<string>, removed: list<string>}
     */
    public function sync(UserRole $role, array $permissions): array
    {
        $wanted = $this->withRequirements($permissions);
        $current = array_map(fn (Permission $permission) => $permission->value, $this->for($role));

        $added = array_values(array_diff($wanted, $current));
        $removed = array_values(array_diff($current, $wanted));

        DB::transaction(function () use ($role, $added, $removed): void {
            if ($removed !== []) {
                DB::table('role_permissions')->where('role', $role->value)->whereIn('permission', $removed)->delete();
            }

            if ($added !== []) {
                DB::table('role_permissions')->insert(
                    array_map(fn (string $permission) => ['role' => $role->value, 'permission' => $permission], $added),
                );
            }
        });

        $this->flush();

        return ['added' => $added, 'removed' => $removed];
    }

    public function flush(): void
    {
        $this->granted = null;
    }

    /**
     * Inclui as permissões das quais as escolhidas dependem (ex.: quem edita também vê).
     *
     * @param  list<Permission>  $permissions
     * @return list<string>
     */
    private function withRequirements(array $permissions): array
    {
        $values = [];
        $pending = $permissions;

        while (($current = array_pop($pending)) !== null) {
            if (! isset($values[$current->value])) {
                $values[$current->value] = true;
                array_push($pending, ...$current->requires());
            }
        }

        return array_keys($values);
    }

    /**
     * @return array<string, array<string, true>>
     */
    private function granted(): array
    {
        if ($this->granted === null) {
            $this->granted = [];

            foreach (DB::table('role_permissions')->get(['role', 'permission']) as $row) {
                $this->granted[$row->role][$row->permission] = true;
            }
        }

        return $this->granted;
    }
}
