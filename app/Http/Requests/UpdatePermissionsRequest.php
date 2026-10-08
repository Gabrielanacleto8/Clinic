<?php

namespace App\Http\Requests;

use App\Enums\Permission;
use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePermissionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage-permissions');
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $rules = ['permissions' => ['nullable', 'array:'.implode(',', array_map(fn (UserRole $role) => $role->value, UserRole::configurable()))]];

        foreach (UserRole::configurable() as $role) {
            $rules["permissions.{$role->value}"] = ['nullable', 'array'];
            $rules["permissions.{$role->value}.*"] = ['string', 'distinct', Rule::enum(Permission::class)];
        }

        return $rules;
    }

    /**
     * Permissões marcadas para o perfil. Perfil sem nenhuma caixa marcada não é enviado pelo navegador.
     *
     * @return list<Permission>
     */
    public function permissionsFor(UserRole $role): array
    {
        return array_map(
            fn (string $value) => Permission::from($value),
            $this->validated("permissions.{$role->value}") ?? [],
        );
    }
}
