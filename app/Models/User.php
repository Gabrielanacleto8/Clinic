<?php

namespace App\Models;

use App\Enums\Permission;
use App\Enums\UserRole;
use App\Services\RolePermissions;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * Perfil, situação e data de troca de senha ficam fora do mass assignment
 * de propósito: só são alterados de forma explícita, por quem tem permissão.
 */
#[Fillable(['name', 'email', 'password', 'professional_registration'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Padrões do banco, para o modelo recém-criado já tê-los sem ser relido.
     *
     * @var array<string, mixed>
     */
    protected $attributes = ['professional_registration' => null];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'is_active' => 'boolean',
            'password_changed_at' => 'datetime',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    /**
     * Como o profissional assina nos documentos: o nome e, se informado, o registro no conselho.
     */
    public function nameWithRegistration(): string
    {
        return $this->name.($this->professional_registration ? ' · '.$this->professional_registration : '');
    }

    public function hasPermission(Permission $permission): bool
    {
        return app(RolePermissions::class)->allows($this->role, $permission);
    }

    /**
     * Usuários ativos que podem ser o profissional responsável por um
     * atendimento: os de perfis com permissão de registrar atendimentos.
     *
     * @param  Builder<User>  $query
     */
    public function scopeAttending(Builder $query): void
    {
        $query->where('is_active', true)->whereIn('role', app(RolePermissions::class)->rolesWith(Permission::RecordsManage));
    }

    public function mustChangePassword(): bool
    {
        return $this->password_changed_at === null;
    }
}
