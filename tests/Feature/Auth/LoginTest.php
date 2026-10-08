<?php

namespace Tests\Feature\Auth;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\User;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_is_rendered(): void
    {
        $this->get('/login')->assertOk()->assertSee('Entrar');
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/')->assertRedirect('/login');
        $this->get('/pacientes')->assertRedirect('/login');
        $this->get('/usuarios')->assertRedirect('/login');
        $this->get('/senha')->assertRedirect('/login');
    }

    public function test_user_can_log_in_with_valid_credentials(): void
    {
        $user = User::factory()->create();

        $this->post('/login', ['email' => $user->email, 'password' => UserFactory::PASSWORD])
            ->assertRedirect(route('home'));

        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::LoginSucceeded->value,
            'user_id' => $user->id,
        ]);
    }

    public function test_session_id_is_regenerated_on_login(): void
    {
        $user = User::factory()->create();

        $this->get('/login');
        $before = session()->getId();

        $this->post('/login', ['email' => $user->email, 'password' => UserFactory::PASSWORD]);

        $this->assertNotSame($before, session()->getId());
    }

    public function test_login_fails_with_wrong_password(): void
    {
        $user = User::factory()->create();

        $this->from('/login')
            ->post('/login', ['email' => $user->email, 'password' => 'senha-errada'])
            ->assertRedirect('/login')
            ->assertSessionHasErrors(['email' => 'E-mail ou senha inválidos.']);

        $this->assertGuest();
        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::LoginFailed->value,
            'auditable_id' => $user->id,
        ]);
    }

    public function test_unknown_email_and_inactive_account_get_the_same_generic_error(): void
    {
        $inactive = User::factory()->inactive()->create();

        $this->post('/login', ['email' => 'ninguem@example.com', 'password' => UserFactory::PASSWORD])
            ->assertSessionHasErrors(['email' => 'E-mail ou senha inválidos.']);

        $this->post('/login', ['email' => $inactive->email, 'password' => UserFactory::PASSWORD])
            ->assertSessionHasErrors(['email' => 'E-mail ou senha inválidos.']);

        $this->assertGuest();
    }

    public function test_password_is_never_flashed_back_to_the_session(): void
    {
        $this->post('/login', ['email' => 'ninguem@example.com', 'password' => 'segredo-digitado']);

        $this->assertArrayNotHasKey('password', session()->getOldInput());
    }

    public function test_login_is_locked_after_too_many_failed_attempts(): void
    {
        $user = User::factory()->create();

        foreach (range(1, 5) as $attempt) {
            $this->post('/login', ['email' => $user->email, 'password' => 'senha-errada']);
        }

        // Mesmo com a senha certa, a sexta tentativa é barrada.
        $response = $this->post('/login', ['email' => $user->email, 'password' => UserFactory::PASSWORD]);

        $response->assertSessionHasErrors('email');
        $this->assertStringContainsString('Muitas tentativas', session('errors')->first('email'));
        $this->assertGuest();
        $this->assertDatabaseHas('audit_logs', ['action' => AuditAction::LoginLockedOut->value]);
    }

    public function test_authenticated_users_are_redirected_away_from_login(): void
    {
        $this->actingAs(User::factory()->create())->get('/login')->assertRedirect('/');
    }

    public function test_user_can_log_out(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/logout')->assertRedirect('/login');

        $this->assertGuest();
        $this->assertDatabaseHas('audit_logs', ['action' => AuditAction::Logout->value, 'user_id' => $user->id]);
    }

    public function test_logout_requires_post(): void
    {
        $this->actingAs(User::factory()->create())->get('/logout')->assertStatus(405);
    }

    public function test_user_deactivated_during_a_session_is_logged_out(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/pacientes')->assertOk();

        $user->forceFill(['is_active' => false])->save();

        $this->get('/pacientes')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_other_sessions_are_invalidated_when_the_password_changes(): void
    {
        $user = User::factory()->create();

        $this->post('/login', ['email' => $user->email, 'password' => UserFactory::PASSWORD]);
        $this->get('/pacientes')->assertOk();

        // Simula a troca de senha feita em outro dispositivo ou pelo administrador.
        User::whereKey($user->id)->update(['password' => Hash::make('Outra-Senha#2026')]);
        Auth::forgetGuards();

        $this->get('/pacientes')->assertRedirect('/login');
    }

    public function test_login_screen_offers_remember_me_unchecked_by_default(): void
    {
        $html = $this->get('/login')->assertOk()->assertSee('Lembrar de mim neste dispositivo')->getContent();

        $this->assertMatchesRegularExpression('/<input type="checkbox" name="remember" value="1"[^>]*>/', $html);
        $this->assertDoesNotMatchRegularExpression('/name="remember"[^>]*checked/', $html);
    }

    public function test_without_remember_me_no_long_lived_cookie_is_issued(): void
    {
        $user = User::factory()->create();

        $this->post('/login', ['email' => $user->email, 'password' => UserFactory::PASSWORD])
            ->assertCookieMissing(Auth::guard()->getRecallerName());

        $this->assertDatabaseHas('audit_logs', ['action' => AuditAction::LoginSucceeded->value, 'metadata' => null]);
    }

    public function test_remember_me_cookie_lasts_the_configured_number_of_days(): void
    {
        config(['auth.guards.web.remember' => 7 * 24 * 60]);
        Auth::forgetGuards();

        $user = User::factory()->create();

        $cookie = $this->post('/login', ['email' => $user->email, 'password' => UserFactory::PASSWORD, 'remember' => '1'])
            ->assertRedirect(route('home'))
            ->getCookie(Auth::guard()->getRecallerName());

        $this->assertNotNull($cookie);
        $this->assertEqualsWithDelta(now()->addDays(7)->getTimestamp(), $cookie->getExpiresTime(), 60);
        // Inacessível a scripts da página.
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertTrue(AuditLog::where('action', AuditAction::LoginSucceeded->value)->sole()->metadata['remember']);
    }

    public function test_default_remember_me_duration_is_thirty_days(): void
    {
        $this->assertSame(30 * 24 * 60, config('auth.guards.web.remember'));
    }

    public function test_remembered_user_is_signed_back_in_after_the_session_ends(): void
    {
        $user = User::factory()->create();

        $this->returnWithRememberCookie($user)->get('/pacientes')->assertOk();

        $this->assertAuthenticatedAs($user);
    }

    public function test_remember_me_does_not_bypass_deactivation(): void
    {
        $user = User::factory()->create();
        $returning = $this->returnWithRememberCookie($user);

        $user->forceFill(['is_active' => false])->save();

        $returning->get('/pacientes')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_remember_me_does_not_bypass_the_forced_password_change(): void
    {
        $user = User::factory()->withTemporaryPassword()->create();

        $this->returnWithRememberCookie($user)->get('/pacientes')->assertRedirect('/senha');
    }

    public function test_remember_me_cookie_dies_when_the_password_changes(): void
    {
        $user = User::factory()->create();
        $returning = $this->returnWithRememberCookie($user);

        User::whereKey($user->id)->update(['password' => Hash::make('Outra-Senha#2026')]);

        $returning->get('/pacientes')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_logout_invalidates_the_remember_me_cookie(): void
    {
        $user = User::factory()->create();
        $name = Auth::guard()->getRecallerName();

        $stolen = $this->post('/login', ['email' => $user->email, 'password' => UserFactory::PASSWORD, 'remember' => '1'])
            ->getCookie($name)->getValue();

        $this->post('/logout');
        $this->flushSession();
        Auth::forgetGuards();

        // Uma cópia antiga do cookie não serve mais depois do logout.
        $this->withCookie($name, $stolen)->get('/pacientes')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_remember_field_is_validated(): void
    {
        $user = User::factory()->create();

        $this->post('/login', ['email' => $user->email, 'password' => UserFactory::PASSWORD, 'remember' => ['x']])
            ->assertSessionHasErrors('remember');

        $this->assertGuest();
    }

    /**
     * Entra marcando "Lembrar de mim" e devolve o teste na situação de quem
     * volta depois: sessão encerrada, só com o cookie de longa duração.
     */
    private function returnWithRememberCookie(User $user): static
    {
        $name = Auth::guard()->getRecallerName();

        $value = $this->post('/login', ['email' => $user->email, 'password' => UserFactory::PASSWORD, 'remember' => '1'])
            ->getCookie($name)->getValue();

        $this->flushSession();
        Auth::forgetGuards();

        return $this->withCookie($name, $value);
    }

    public function test_audit_log_never_stores_the_attempted_password(): void
    {
        $user = User::factory()->create();

        $this->post('/login', ['email' => $user->email, 'password' => 'senha-digitada-errada']);

        $this->assertSame(1, AuditLog::count());
        $this->assertStringNotContainsString('senha-digitada-errada', (string) json_encode(AuditLog::first()->getAttributes()));
    }
}
