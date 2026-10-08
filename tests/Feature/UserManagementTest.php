<?php

namespace Tests\Feature;

use App\Enums\AuditAction;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Provisoria#Senha2026';

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'name' => 'Maria Recepção',
            'email' => 'maria@example.com',
            'role' => UserRole::Receptionist->value,
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
            ...$overrides,
        ];
    }

    #[DataProvider('nonAdminRoles')]
    public function test_non_admins_cannot_manage_users(UserRole $role): void
    {
        $actor = User::factory()->role($role)->create();
        $target = User::factory()->create();

        $this->actingAs($actor);

        $this->get('/usuarios')->assertForbidden();
        $this->get('/usuarios/novo')->assertForbidden();
        $this->post('/usuarios', $this->payload())->assertForbidden();
        $this->get("/usuarios/{$target->id}/editar")->assertForbidden();
        $this->put("/usuarios/{$target->id}", $this->payload(['role' => UserRole::Admin->value]))->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'maria@example.com']);
        $this->assertSame(UserRole::Receptionist, $target->refresh()->role);
    }

    public function test_non_admin_cannot_promote_themself(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->put("/usuarios/{$user->id}", $this->payload(['role' => UserRole::Admin->value, 'is_active' => '1']))
            ->assertForbidden();

        $this->assertSame(UserRole::Receptionist, $user->refresh()->role);
    }

    public function test_admin_can_list_users(): void
    {
        $admin = User::factory()->admin()->create();
        $other = User::factory()->professional()->create();

        $this->actingAs($admin)->get('/usuarios')->assertOk()->assertSee($other->email)->assertSee('Profissional');
    }

    public function test_admin_can_create_a_user_with_a_temporary_password(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post('/usuarios', $this->payload(['email' => 'MARIA@Example.com', 'role' => UserRole::Professional->value]))
            ->assertRedirect(route('users.index'))
            ->assertSessionHasNoErrors();

        $user = User::where('email', 'maria@example.com')->firstOrFail();

        $this->assertSame(UserRole::Professional, $user->role);
        $this->assertTrue($user->is_active);
        $this->assertTrue($user->mustChangePassword());
        $this->assertNotSame(self::PASSWORD, $user->password);
        $this->assertTrue(Hash::check(self::PASSWORD, $user->password));
        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::UserCreated->value,
            'user_id' => $admin->id,
            'auditable_id' => $user->id,
        ]);
    }

    public function test_user_creation_is_validated(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->create(['email' => 'maria@example.com']);

        $this->actingAs($admin)
            ->post('/usuarios', $this->payload(['role' => 'superuser', 'password' => 'fraca', 'password_confirmation' => 'fraca']))
            ->assertSessionHasErrors(['email', 'role', 'password']);

        $this->assertSame(1, User::where('email', 'maria@example.com')->count());
    }

    public function test_admin_can_update_role_and_deactivate_a_user(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();

        $this->actingAs($admin)->put("/usuarios/{$user->id}", [
            'name' => 'Nome Atualizado',
            'email' => $user->email,
            'role' => UserRole::Professional->value,
            'is_active' => '0',
        ])->assertRedirect(route('users.index'))->assertSessionHasNoErrors();

        $user->refresh();

        $this->assertSame('Nome Atualizado', $user->name);
        $this->assertSame(UserRole::Professional, $user->role);
        $this->assertFalse($user->is_active);
        // Sem senha no envio, a senha e a obrigação de troca não mudam.
        $this->assertFalse($user->mustChangePassword());
        $this->assertDatabaseHas('audit_logs', ['action' => AuditAction::UserUpdated->value, 'auditable_id' => $user->id]);
        $this->assertDatabaseMissing('audit_logs', ['action' => AuditAction::UserPasswordReset->value]);
    }

    public function test_admin_cannot_demote_or_deactivate_their_own_account(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->put("/usuarios/{$admin->id}", [
            'name' => $admin->name,
            'email' => $admin->email,
            'role' => UserRole::Receptionist->value,
            'is_active' => '0',
        ])->assertSessionHasErrors(['role', 'is_active']);

        $admin->refresh();

        $this->assertTrue($admin->isAdmin());
        $this->assertTrue($admin->is_active);
    }

    public function test_admin_can_reset_a_user_password(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();

        $this->actingAs($admin)->put("/usuarios/{$user->id}", [
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role->value,
            'is_active' => '1',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
        ])->assertSessionHasNoErrors();

        $user->refresh();

        $this->assertTrue(Hash::check(self::PASSWORD, $user->password));
        $this->assertTrue($user->mustChangePassword());
        $this->assertDatabaseHas('audit_logs', ['action' => AuditAction::UserPasswordReset->value, 'auditable_id' => $user->id]);
    }

    public function test_password_hash_is_never_rendered(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();

        $this->actingAs($admin)->get("/usuarios/{$user->id}/editar")->assertOk()->assertDontSee($user->password);
        $this->assertArrayNotHasKey('password', $user->toArray());
    }

    /**
     * @return array<string, array{UserRole}>
     */
    public static function nonAdminRoles(): array
    {
        return [
            'profissional' => [UserRole::Professional],
            'recepção' => [UserRole::Receptionist],
        ];
    }
}
