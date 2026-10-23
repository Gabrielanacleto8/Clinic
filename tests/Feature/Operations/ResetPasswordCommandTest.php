<?php

namespace Tests\Feature\Operations;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ResetPasswordCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_sets_a_temporary_password_that_must_be_changed(): void
    {
        $admin = User::factory()->admin()->create(['email' => 'ana@example.com']);
        $oldRememberToken = $admin->getRememberToken();

        $this->artisan('clinic:reset-password', ['email' => ' Ana@Example.com '])
            ->expectsQuestion('Senha provisória', 'Provisoria#2026')
            ->expectsQuestion('Confirme a senha', 'Provisoria#2026')
            ->expectsOutputToContain('A troca será exigida no próximo acesso')
            ->assertSuccessful();

        $admin->refresh();

        $this->assertTrue(Hash::check('Provisoria#2026', $admin->password));
        $this->assertTrue($admin->mustChangePassword());
        // "Lembrar de mim" de todos os dispositivos deixa de valer.
        $this->assertNotSame($oldRememberToken, $admin->getRememberToken());

        $log = AuditLog::where('action', AuditAction::UserPasswordReset->value)->sole();
        $this->assertSame($admin->id, $log->auditable_id);
        $this->assertNull($log->user_id);
        $this->assertSame(['via' => 'console'], $log->metadata);
    }

    public function test_the_new_password_lets_the_user_in_even_after_a_lockout(): void
    {
        $admin = User::factory()->admin()->create(['email' => 'ana@example.com']);

        foreach (range(1, 10) as $attempt) {
            $this->withServerVariables(['REMOTE_ADDR' => "10.0.0.{$attempt}"])
                ->post('/login', ['email' => 'ana@example.com', 'password' => 'errada']);
        }

        $this->artisan('clinic:reset-password', ['email' => 'ana@example.com'])
            ->expectsQuestion('Senha provisória', 'Provisoria#2026')
            ->expectsQuestion('Confirme a senha', 'Provisoria#2026')
            ->assertSuccessful();

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.1.1'])
            ->post('/login', ['email' => 'ana@example.com', 'password' => 'Provisoria#2026'])
            ->assertSessionHasNoErrors();

        $this->assertAuthenticatedAs($admin->fresh());
    }

    public function test_it_fails_for_an_unknown_email(): void
    {
        $this->artisan('clinic:reset-password', ['email' => 'ninguem@example.com'])
            ->expectsOutputToContain('Nenhum usuário com esse e-mail')
            ->assertFailed();

        $this->assertSame(0, AuditLog::count());
    }

    public function test_it_fails_when_the_confirmation_does_not_match(): void
    {
        $admin = User::factory()->admin()->create(['email' => 'ana@example.com']);
        $hash = $admin->password;

        $this->artisan('clinic:reset-password', ['email' => 'ana@example.com'])
            ->expectsQuestion('Senha provisória', 'Provisoria#2026')
            ->expectsQuestion('Confirme a senha', 'Outra#Senha2026')
            ->assertFailed();

        $this->assertSame($hash, $admin->fresh()->password);
        $this->assertSame(0, AuditLog::count());
    }

    public function test_it_refuses_to_run_without_an_interactive_terminal(): void
    {
        User::factory()->admin()->create(['email' => 'ana@example.com']);

        $this->artisan('clinic:reset-password', ['email' => 'ana@example.com', '--no-interaction' => true])
            ->expectsOutputToContain('terminal interativo')
            ->assertFailed();
    }

    public function test_it_warns_when_the_user_is_deactivated(): void
    {
        User::factory()->inactive()->create(['email' => 'bia@example.com']);

        $this->artisan('clinic:reset-password', ['email' => 'bia@example.com'])
            ->expectsQuestion('Senha provisória', 'Provisoria#2026')
            ->expectsQuestion('Confirme a senha', 'Provisoria#2026')
            ->expectsOutputToContain('desativado')
            ->assertSuccessful();
    }
}
