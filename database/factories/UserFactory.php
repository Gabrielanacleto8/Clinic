<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * Senha em texto puro usada pela factory. Atende à política de senhas.
     */
    public const PASSWORD = 'Senha-Forte#2026';

    protected static ?string $password;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => static::$password ??= Hash::make(self::PASSWORD),
            'remember_token' => Str::random(10),
            'role' => UserRole::Receptionist,
            'is_active' => true,
            'password_changed_at' => now(),
        ];
    }

    public function admin(): static
    {
        return $this->state(['role' => UserRole::Admin]);
    }

    public function professional(): static
    {
        return $this->state(['role' => UserRole::Professional]);
    }

    public function role(UserRole $role): static
    {
        return $this->state(['role' => $role]);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }

    /**
     * Senha provisória: precisa ser trocada no primeiro acesso.
     */
    public function withTemporaryPassword(): static
    {
        return $this->state(['password_changed_at' => null]);
    }
}
