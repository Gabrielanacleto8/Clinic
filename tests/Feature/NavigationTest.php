<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NavigationTest extends TestCase
{
    use RefreshDatabase;

    private function sidebar(string $html): string
    {
        $this->assertSame(1, preg_match('/<aside id="sidebar".*?<\/aside>/s', $html, $matches), 'Menu lateral não encontrado.');

        return $matches[0];
    }

    public function test_sidebar_lists_every_module_for_admins(): void
    {
        $admin = User::factory()->admin()->create(['name' => 'Ana Admin']);

        $sidebar = $this->sidebar($this->actingAs($admin)->get('/pacientes')->assertOk()->getContent());

        $this->assertStringContainsString('href="'.route('patients.index').'"', $sidebar);
        $this->assertStringContainsString('href="'.route('users.index').'"', $sidebar);
        $this->assertStringContainsString('href="'.route('password.edit').'"', $sidebar);
        $this->assertStringContainsString('action="'.route('logout').'"', $sidebar);
        $this->assertStringContainsString('Ana Admin', $sidebar);
        $this->assertStringContainsString('Administrador', $sidebar);
    }

    public function test_sidebar_hides_modules_the_user_cannot_access(): void
    {
        $sidebar = $this->sidebar($this->actingAs(User::factory()->create())->get('/pacientes')->getContent());

        $this->assertStringContainsString('href="'.route('patients.index').'"', $sidebar);
        $this->assertStringNotContainsString('href="'.route('users.index').'"', $sidebar);
        $this->assertStringNotContainsString('Administração', $sidebar);
    }

    public function test_sidebar_marks_the_current_module(): void
    {
        $admin = User::factory()->admin()->create();

        $onPatients = $this->sidebar($this->actingAs($admin)->get('/pacientes/novo')->getContent());
        $onUsers = $this->sidebar($this->actingAs($admin)->get('/usuarios')->getContent());

        $this->assertMatchesRegularExpression('/href="[^"]*\/pacientes"[^>]*aria-current="page"/', $onPatients);
        $this->assertDoesNotMatchRegularExpression('/href="[^"]*\/usuarios"[^>]*aria-current="page"/', $onPatients);
        $this->assertMatchesRegularExpression('/href="[^"]*\/usuarios"[^>]*aria-current="page"/', $onUsers);
    }

    public function test_sidebar_lists_no_modules_while_the_password_is_temporary(): void
    {
        $user = User::factory()->admin()->withTemporaryPassword()->create();

        $sidebar = $this->sidebar($this->actingAs($user)->get('/senha')->assertOk()->getContent());

        $this->assertStringNotContainsString('href="'.route('patients.index').'"', $sidebar);
        $this->assertStringNotContainsString('href="'.route('users.index').'"', $sidebar);
        $this->assertStringContainsString('action="'.route('logout').'"', $sidebar);
    }

    public function test_sidebar_is_expanded_by_default(): void
    {
        $html = $this->actingAs(User::factory()->create())->get('/pacientes')->getContent();

        $this->assertStringContainsString('<html lang="pt-BR" data-sidebar="expanded">', $html);
        $this->assertMatchesRegularExpression('/data-sidebar-collapse[^>]*aria-expanded="true"[^>]*aria-label="Recolher menu"/s', $html);
    }

    public function test_sidebar_is_rendered_collapsed_when_the_preference_cookie_says_so(): void
    {
        $html = $this->actingAs(User::factory()->create())
            ->withUnencryptedCookie('sidebar', 'collapsed')
            ->get('/pacientes')
            ->getContent();

        $this->assertStringContainsString('<html lang="pt-BR" data-sidebar="collapsed">', $html);
        $this->assertMatchesRegularExpression('/data-sidebar-collapse[^>]*aria-expanded="false"[^>]*aria-label="Expandir menu"/s', $html);
        // Recolhida, os módulos continuam todos presentes e com nome acessível.
        $this->assertStringContainsString('Pacientes', $this->sidebar($html));
    }

    public function test_sidebar_preference_cookie_is_never_reflected_into_the_page(): void
    {
        $payload = '"><script>alert(1)</script>';

        $html = $this->actingAs(User::factory()->create())
            ->withUnencryptedCookie('sidebar', $payload)
            ->get('/pacientes')
            ->getContent();

        $this->assertStringContainsString('data-sidebar="expanded"', $html);
        $this->assertStringNotContainsString('alert(1)', $html);
    }

    public function test_user_initials_are_shown_in_the_sidebar(): void
    {
        $user = User::factory()->create(['name' => 'maria de souza lima']);

        $this->assertMatchesRegularExpression('/aria-hidden="true">ML<\/span>/', $this->sidebar($this->actingAs($user)->get('/pacientes')->getContent()));
    }

    public function test_sidebar_has_no_password_item_and_the_account_opens_from_the_user_name(): void
    {
        $user = User::factory()->create(['name' => 'Maria Lima']);

        $sidebar = $this->sidebar($this->actingAs($user)->get('/pacientes')->getContent());

        $this->assertStringNotContainsStringIgnoringCase('Alterar senha', $sidebar);
        $this->assertStringNotContainsStringIgnoringCase('Redefinir senha', $sidebar);
        $this->assertMatchesRegularExpression('/<a href="'.preg_quote(route('password.edit'), '/').'"[^>]*title="Minha conta".*?Maria Lima.*?<\/a>/s', $sidebar);

        // A tela de troca de senha continua existindo.
        $this->get('/senha')->assertOk()->assertSee('Alterar senha');
    }

    public function test_scheduling_screens_keep_the_agenda_module_highlighted(): void
    {
        $user = User::factory()->create();

        foreach (['/agenda', '/agendamentos/novo'] as $path) {
            $nav = $this->sidebar($this->actingAs($user)->get($path)->getContent());

            $this->assertMatchesRegularExpression('/<a href="'.preg_quote(route('agenda.index'), '/').'"[^>]*aria-current="page"/', $nav);
            $this->assertDoesNotMatchRegularExpression('/<a href="'.preg_quote(route('home'), '/').'"[^>]*aria-current="page"/', $nav);
        }
    }

    public function test_guest_pages_have_no_sidebar(): void
    {
        $this->assertStringNotContainsString('id="sidebar"', $this->get('/login')->getContent());
    }
}
