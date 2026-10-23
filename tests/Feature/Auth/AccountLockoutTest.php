<?php

namespace Tests\Feature\Auth;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\User;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountLockoutTest extends TestCase
{
    use RefreshDatabase;

    private function failFrom(string $ip, string $email): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->post('/login', ['email' => $email, 'password' => 'senha-errada']);
    }

    public function test_ten_failures_from_different_ips_lock_the_account_for_fifteen_minutes(): void
    {
        $user = User::factory()->create();

        foreach (range(1, 10) as $attempt) {
            $this->failFrom("10.0.0.{$attempt}", $user->email);
        }

        // Nem a senha certa, de um IP novo, passa.
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.1.1'])
            ->post('/login', ['email' => $user->email, 'password' => UserFactory::PASSWORD])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertStringContainsString('Acesso bloqueado por excesso de tentativas', session('errors')->first('email'));
        $this->assertStringContainsString('15 minuto(s)', session('errors')->first('email'));
        $this->assertSame(['scope' => 'account'], AuditLog::where('action', AuditAction::LoginLockedOut->value)->latest('id')->first()->metadata);

        // Passado o bloqueio, a senha certa volta a funcionar.
        $this->travel(16)->minutes();

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.1.1'])
            ->post('/login', ['email' => $user->email, 'password' => UserFactory::PASSWORD])
            ->assertRedirect('/');

        $this->assertAuthenticatedAs($user);
    }

    public function test_the_lockout_does_not_reveal_whether_the_email_exists(): void
    {
        foreach (range(1, 10) as $attempt) {
            $this->failFrom("10.0.0.{$attempt}", 'ninguem@example.com');
        }

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.1.1'])
            ->post('/login', ['email' => 'ninguem@example.com', 'password' => 'qualquer'])
            ->assertSessionHasErrors('email');

        $this->assertStringContainsString('Acesso bloqueado por excesso de tentativas', session('errors')->first('email'));
    }

    public function test_a_successful_login_resets_the_account_counter(): void
    {
        $user = User::factory()->create();

        foreach (range(1, 4) as $attempt) {
            $this->failFrom("10.0.0.{$attempt}", $user->email);
        }

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.50'])
            ->post('/login', ['email' => $user->email, 'password' => UserFactory::PASSWORD]);
        $this->post('/logout');

        foreach (range(5, 13) as $attempt) {
            $this->failFrom("10.0.0.{$attempt}", $user->email);
        }

        // Só 9 falhas depois do acesso certo: ainda não bloqueia.
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.1.1'])
            ->post('/login', ['email' => $user->email, 'password' => UserFactory::PASSWORD])
            ->assertRedirect('/');
    }

    public function test_the_admin_password_reset_releases_a_locked_account(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();

        foreach (range(1, 10) as $attempt) {
            $this->failFrom("10.0.0.{$attempt}", $user->email);
        }

        $this->actingAs($admin)->put("/usuarios/{$user->id}", [
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role->value,
            'is_active' => '1',
            'password' => 'Provisoria#2026',
            'password_confirmation' => 'Provisoria#2026',
        ])->assertRedirect('/usuarios');
        $this->post('/logout');

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.1.1'])
            ->post('/login', ['email' => $user->email, 'password' => 'Provisoria#2026'])
            ->assertSessionHasNoErrors();

        $this->assertAuthenticatedAs($user->fresh());
    }
}
