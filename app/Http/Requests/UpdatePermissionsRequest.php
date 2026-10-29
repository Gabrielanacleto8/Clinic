<?php

namespace App\Http\Requests;

use App\Enums\Permission;
use App\Enums\UserRole;
use App\Support\LocalizedDecimal;
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
            $rules["discount_limits.{$role->value}"] = ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:100'];
        }

        $rules['discount_limits'] = ['nullable', 'array:'.implode(',', array_map(fn (UserRole $role) => $role->value, UserRole::configurable()))];

        return $rules;
    }

    protected function prepareForValidation(): void
    {
        if (is_array($this->input('discount_limits'))) {
            $this->merge(['discount_limits' => array_map(fn ($value) => LocalizedDecimal::normalize($value), $this->input('discount_limits'))]);
        }
    }

    /**
     * Desconto máximo informado para cada perfil (em %). Perfil em branco fica como está.
     *
     * @return array<string, float>
     */
    public function discountLimits(): array
    {
        return array_map('floatval', array_filter($this->validated('discount_limits') ?? [], fn ($value) => $value !== null && $value !== ''));
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['discount_limits.*' => 'desconto máximo'];
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
