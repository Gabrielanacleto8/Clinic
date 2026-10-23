<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * Peças comuns a todas as telas: quem está conectado no cabeçalho, resumo
 * de erros no topo do formulário, dica ligada ao campo e mensagens em português.
 */
class InterfaceConventionsTest extends TestCase
{
    use RefreshDatabase;

    private function header(string $html): string
    {
        $this->assertSame(1, preg_match('/<header\b.*?<\/header>/s', $html, $matches), 'Cabeçalho não encontrado.');

        return $matches[0];
    }

    public function test_the_header_shows_who_is_signed_in_and_a_logout_button(): void
    {
        $header = $this->header($this->actingAs(User::factory()->create(['name' => 'Carla Recepção']))->get('/pacientes')->assertOk()->getContent());

        $this->assertStringContainsString('Carla', $header);
        $this->assertStringContainsString('href="'.route('password.edit').'"', $header);
        $this->assertMatchesRegularExpression('/<form method="POST" action="'.preg_quote(route('logout'), '/').'"[^>]*>.*?Sair<\/button>/s', $header);
    }

    public function test_the_default_locale_is_brazilian_portuguese(): void
    {
        $this->assertSame('pt_BR', config('app.locale'));
        $this->assertSame('pt_BR', app()->getLocale());

        // Mensagem de validação sem tradução própria vem do arquivo em português, não do inglês.
        $message = Validator::make(['campo' => ''], ['campo' => 'required'])->errors()->first('campo');
        $this->assertStringNotContainsString('field is required', $message);
    }

    public function test_a_long_form_shows_the_error_summary_on_top_and_links_hint_and_error_to_the_field(): void
    {
        $this->actingAs(User::factory()->create());

        // Sem erro: a dica é anunciada junto do campo e não há resumo.
        $this->get('/pacientes/novo')->assertOk()
            ->assertSee('aria-describedby="guardian_cpf-hint"', false)
            ->assertSee('aria-describedby="notes-hint"', false)
            ->assertDontSee('data-form-errors', false);

        $response = $this->from('/pacientes/novo')->followingRedirects()
            ->post('/pacientes', ['name' => '', 'cpf' => '390.533.447-05', 'birth_date' => '1985-03-20', 'guardian_cpf' => '111.111.111-11'])
            ->assertOk();

        $response->assertSee('data-form-errors', false)
            ->assertSee('role="alert"', false)
            ->assertSee('Confira os campos destacados:')
            ->assertSee('aria-describedby="guardian_cpf-hint guardian_cpf-error"', false)
            ->assertSee('aria-invalid="true"', false);
    }

    public function test_the_summary_on_top_ignores_errors_that_came_from_a_dialog(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        // Erro de um diálogo é mostrado no próprio diálogo, não no topo da página.
        $this->from('/estoque/entradas')->followingRedirects()
            ->post('/estoque/entradas', ['_dialog' => 'stock-entry', 'product_id' => '', 'batch_code' => '', 'quantity' => ''])
            ->assertOk()
            ->assertSee('aria-invalid="true"', false)
            ->assertDontSee('data-form-errors', false);
    }
}
