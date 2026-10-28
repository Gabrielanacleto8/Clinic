<?php

namespace Tests\Feature;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\Patient;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Página "Meu perfil", pesquisa do topo e aparência (claro, escuro ou automático).
 */
class ProfileSearchAndThemeTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_shows_own_data_recent_logins_and_permissions(): void
    {
        $user = User::factory()->professional()->create(['name' => 'Helena Souza', 'email' => 'helena@clinica.test', 'professional_registration' => 'CRBM-1 777']);
        AuditLog::create(['user_id' => $user->id, 'action' => AuditAction::LoginSucceeded, 'ip_address' => '10.0.0.7', 'user_agent' => 'Mozilla/5.0 (Linux; Android 14) Chrome/130.0 Mobile Safari/537.36']);
        // O acesso de outra pessoa não aparece.
        AuditLog::create(['user_id' => User::factory()->create()->id, 'action' => AuditAction::LoginSucceeded, 'ip_address' => '10.9.9.9', 'user_agent' => 'x']);

        $this->actingAs($user)->get('/perfil')
            ->assertOk()
            ->assertSee('helena@clinica.test')
            ->assertSee('CRBM-1 777')
            ->assertSee('Chrome no Android')
            ->assertSee('IP 10.0.0.7')
            ->assertDontSee('10.9.9.9')
            ->assertSee('Registrar atendimentos e preencher a ficha de saúde')
            ->assertDontSee('Ver todo o financeiro')
            ->assertSee('Alterar senha');
    }

    public function test_guests_and_users_with_a_temporary_password_do_not_reach_the_profile(): void
    {
        $this->get('/perfil')->assertRedirect('/login');
        $this->get('/busca?q=ana')->assertRedirect('/login');

        $this->actingAs(User::factory()->create(['password_changed_at' => null]))->get('/perfil')->assertRedirect(route('password.edit'));
    }

    public function test_password_changed_from_the_profile_returns_to_it(): void
    {
        $user = User::factory()->create(['password' => 'Senha-Antiga1']);

        $this->actingAs($user)->put('/senha', [
            'current_password' => 'Senha-Antiga1', 'password' => 'Senha-Nova22', 'password_confirmation' => 'Senha-Nova22', 'origem' => 'perfil',
        ])->assertRedirect(route('profile.show'));

        $this->put('/senha', ['current_password' => 'x', 'password' => 'Senha-Nova33', 'password_confirmation' => 'Senha-Nova33', 'origem' => 'https://evil.test'])
            ->assertSessionHasErrors('origem');
    }

    public function test_the_avatar_and_name_open_the_profile(): void
    {
        $html = $this->actingAs(User::factory()->create(['name' => 'Carla Recepção']))->get('/pacientes')->getContent();

        $this->assertMatchesRegularExpression('/<a href="'.preg_quote(route('profile.show'), '/').'"[^>]*title="Meu perfil"/s', $html);
    }

    public function test_theme_choice_is_kept_in_a_cookie_and_rendered_by_the_server(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/perfil/aparencia', ['theme' => 'dark'])
            ->assertRedirect(route('profile.show'))
            ->assertPlainCookie('theme', 'dark');

        $this->post('/perfil/aparencia', ['theme' => 'roxo'])->assertSessionHasErrors('theme');

        $this->withUnencryptedCookie('theme', 'dark')->get('/pacientes')->assertSee('data-theme="dark"', false);
        $this->withUnencryptedCookie('theme', '"><script>alert(1)</script>')->get('/pacientes')
            ->assertSee('data-theme="system"', false)
            ->assertDontSee('alert(1)', false);
    }

    public function test_search_finds_patients_by_name_and_offers_modules(): void
    {
        Patient::factory()->create(['name' => 'Mariana Costa']);
        Patient::factory()->create(['name' => 'João Pereira']);
        Product::factory()->create(['name' => 'Mariana Sérum']);

        // A recepção vê pacientes, mas não o estoque.
        $this->actingAs(User::factory()->create())->get('/busca?q=mariana')
            ->assertOk()
            ->assertSee('Mariana Costa')
            ->assertDontSee('João Pereira')
            ->assertDontSee('Mariana Sérum');

        $this->get('/busca?q=agen')->assertSee(route('agenda.index'), false);
        $this->get('/busca?q=estoque')->assertDontSee(route('products.index'), false);

        $this->actingAs(User::factory()->admin()->create())->get('/busca?q=mariana')->assertSee('Mariana Sérum');
    }

    public function test_search_needs_two_letters_and_rejects_long_terms(): void
    {
        Patient::factory()->create(['name' => 'Ana']);

        $this->actingAs(User::factory()->create());

        $this->get('/busca?q=a')->assertOk()->assertSee('Digite ao menos 2 letras')->assertDontSee('href="'.route('patients.index').'?', false);
        $this->get('/busca?q='.str_repeat('a', 101))->assertSessionHasErrors('q');
        $this->get('/busca?q=zzzz')->assertSee('Nada encontrado');
    }

    public function test_header_has_the_search_and_the_dark_mode_toggle(): void
    {
        $this->actingAs(User::factory()->create())->get('/pacientes')
            ->assertSee('data-global-search', false)
            ->assertSee('data-theme-toggle', false);
    }
}
