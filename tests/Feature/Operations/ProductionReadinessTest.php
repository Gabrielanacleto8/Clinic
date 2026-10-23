<?php

namespace Tests\Feature\Operations;

use App\Enums\AuditAction;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\ProductionConfig;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ProductionReadinessTest extends TestCase
{
    use RefreshDatabase;

    // --- Logs sem dados de paciente ------------------------------------

    public function test_database_errors_are_logged_without_the_values_of_the_query(): void
    {
        User::factory()->create(['email' => 'maria.sigilosa@example.com']);

        /** @var list<MessageLogged> $logged */
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged) {
            $logged[] = $event;
        });

        try {
            DB::table('users')->insert(['name' => 'Maria Sigilosa', 'email' => 'maria.sigilosa@example.com', 'password' => 'x', 'role' => 'admin']);
            $this->fail('A inserção duplicada deveria falhar.');
        } catch (QueryException $exception) {
            // A própria mensagem da exceção já não traz os valores da consulta.
            $this->assertStringNotContainsString('Maria Sigilosa', $exception->getMessage());
            report($exception);
        }

        $this->assertCount(1, $logged);
        $serialized = json_encode([$logged[0]->message, $logged[0]->context], JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString('maria.sigilosa', $serialized);
        $this->assertStringNotContainsString('Maria Sigilosa', $serialized);
        $this->assertSame('error', $logged[0]->level);
        $this->assertSame('23000', $logged[0]->context['sqlstate']);
        $this->assertSame(1062, $logged[0]->context['driver_code']);
        $this->assertStringContainsString('insert into `users`', $logged[0]->context['sql']);
        $this->assertStringContainsString('?', $logged[0]->context['sql']);
        $this->assertStringStartsWith('tests/Feature/Operations/ProductionReadinessTest.php:', $logged[0]->context['origin']);
        $this->assertArrayNotHasKey('exception', $logged[0]->context);
    }

    public function test_the_test_suite_does_not_write_to_the_development_log(): void
    {
        // LOG_CHANNEL=null no phpunit.xml: nos testes, canal nulo vira o NullHandler.
        $this->assertContains(config('logging.default'), [null, 'null']);
    }

    // --- Proxy reverso ---------------------------------------------------

    public function test_without_trusted_proxies_forwarded_headers_are_ignored(): void
    {
        config(['trustedproxy.proxies' => null]);
        $user = User::factory()->create();

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.5'])
            ->withHeaders(['X-Forwarded-For' => '203.0.113.9', 'X-Forwarded-Proto' => 'https'])
            ->post('/login', ['email' => $user->email, 'password' => 'errada'])
            ->assertHeaderMissing('Strict-Transport-Security');

        $this->assertSame('10.0.0.5', AuditLog::where('action', AuditAction::LoginFailed->value)->sole()->ip_address);
    }

    public function test_behind_a_trusted_proxy_the_real_ip_and_https_are_used(): void
    {
        config(['trustedproxy.proxies' => '10.0.0.0/8']);
        $user = User::factory()->create();

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.5'])
            ->withHeaders(['X-Forwarded-For' => '203.0.113.9', 'X-Forwarded-Proto' => 'https'])
            ->post('http://localhost/login', ['email' => $user->email, 'password' => 'errada'])
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');

        $this->assertSame('203.0.113.9', AuditLog::where('action', AuditAction::LoginFailed->value)->sole()->ip_address);
    }

    public function test_no_proxy_is_trusted_by_default(): void
    {
        $this->assertNull((require config_path('trustedproxy.php'))['proxies']);
    }

    // --- Padrões de produção ---------------------------------------------

    /**
     * Avalia um arquivo de configuração com APP_ENV trocado.
     *
     * @return array<string, mixed>
     */
    private function configFileUnder(string $environment, string $file, array $env = []): array
    {
        $vars = ['APP_ENV' => $environment, ...$env];
        $backup = [];

        foreach ($vars as $name => $value) {
            $backup[$name] = [$_ENV[$name] ?? null, $_SERVER[$name] ?? null, getenv($name)];
            $_ENV[$name] = $_SERVER[$name] = $value;
            putenv("{$name}={$value}");
        }

        try {
            return require config_path($file);
        } finally {
            foreach ($backup as $name => [$env, $server, $put]) {
                $env === null ? $_ENV[$name] = null : $_ENV[$name] = $env;
                $server === null ? $_SERVER[$name] = null : $_SERVER[$name] = $server;
                $put === false ? putenv($name) : putenv("{$name}={$put}");

                if ($env === null) {
                    unset($_ENV[$name]);
                }

                if ($server === null) {
                    unset($_SERVER[$name]);
                }
            }
        }
    }

    public function test_production_always_disables_debug_and_secures_the_session_cookie(): void
    {
        $insecure = ['APP_DEBUG' => 'true', 'SESSION_SECURE_COOKIE' => 'false', 'SESSION_ENCRYPT' => 'false'];

        $this->assertFalse($this->configFileUnder('production', 'app.php', $insecure)['debug']);
        $session = $this->configFileUnder('production', 'session.php', $insecure);
        $this->assertTrue($session['secure']);
        $this->assertTrue($session['encrypt']);

        // Fora de produção, as variáveis valem.
        $this->assertTrue($this->configFileUnder('local', 'app.php', $insecure)['debug']);
        $this->assertFalse((bool) $this->configFileUnder('local', 'session.php', $insecure)['secure']);
    }

    public function test_private_files_have_no_public_route(): void
    {
        $this->assertFalse(config('filesystems.disks.local.serve'));
        $this->assertFalse(config('filesystems.disks.patient_photos.serve'));
        $this->get('/storage/qualquer-arquivo')->assertNotFound();
    }

    public function test_robots_are_kept_out(): void
    {
        $this->assertStringContainsString('Disallow: /', (string) file_get_contents(public_path('robots.txt')));
    }

    public function test_production_config_lists_missing_keys_without_their_values(): void
    {
        config(['app.key' => '', 'clinic.blind_index_key' => 'xyz-segredo-123', 'app.url' => 'http://clinica.example.com']);

        $problems = ProductionConfig::problems();

        $this->assertCount(3, $problems);
        $this->assertStringContainsString('APP_KEY', $problems[0]);
        $this->assertStringContainsString('BLIND_INDEX_KEY', $problems[1]);
        $this->assertStringContainsString('APP_URL', $problems[2]);
        $this->assertStringNotContainsString('xyz-segredo-123', implode(' ', $problems));
    }

    public function test_check_command_reports_problems_and_passes_when_ready(): void
    {
        $photos = sys_get_temp_dir().'/clinic-check-'.uniqid();
        mkdir($photos, 0700);
        config(['app.url' => 'https://clinica.example.com', 'filesystems.disks.patient_photos.root' => $photos]);

        $this->artisan('clinic:check')->expectsOutputToContain('Configuração pronta para produção')->assertSuccessful();

        config(['filesystems.disks.patient_photos.root' => $photos.'/nao-existe']);
        $this->artisan('clinic:check')->expectsOutputToContain('PATIENT_PHOTOS_PATH')->assertFailed();

        rmdir($photos);
    }

    // --- Páginas de erro -------------------------------------------------

    public function test_error_pages_are_in_portuguese_and_have_no_inline_style(): void
    {
        $response = $this->get('/pagina-que-nao-existe');

        $response->assertNotFound()->assertSee('Página não encontrada')->assertSee('Voltar ao início');
        $this->assertStringNotContainsString('<style', $response->getContent());
        $this->assertStringNotContainsString('style="', $response->getContent());
    }

    public function test_forbidden_page_explains_the_profile_lacks_permission(): void
    {
        $this->actingAs(User::factory()->role(UserRole::Receptionist)->create())
            ->get('/usuarios')
            ->assertForbidden()
            ->assertSee('Acesso negado')
            ->assertSee('fale com o administrador');
    }

    public function test_server_error_page_does_not_leak_the_exception_message(): void
    {
        config(['app.debug' => false]);
        Route::get('/_teste/falha', fn () => throw new \RuntimeException('Paciente Maria Sigilosa'))->middleware('web');

        $response = $this->get('/_teste/falha');

        $response->assertStatus(500)->assertSee('Erro no sistema');
        $this->assertStringNotContainsString('Maria Sigilosa', $response->getContent());
    }

    // --- Sessão expirada (419) ------------------------------------------

    public function test_expired_session_sends_the_guest_to_login_with_an_explanation(): void
    {
        Route::post('/_teste/formulario', fn () => throw new TokenMismatchException)->middleware('web');

        $this->post('/_teste/formulario', ['description' => 'texto'])
            ->assertRedirect('/login')
            ->assertSessionHas('warning', fn (string $message) => str_contains($message, 'sessão expirou'));

        $this->get('/login')->assertSee('Sua sessão expirou por inatividade');
    }

    public function test_remembered_user_goes_back_to_the_form_without_the_typed_text(): void
    {
        Route::post('/_teste/formulario', fn () => throw new TokenMismatchException)->middleware('web');

        // A aba antiga pode ser de outra pessoa (computador compartilhado): o texto não volta.
        $this->actingAs(User::factory()->create())
            ->from('/pacientes')
            ->post('/_teste/formulario', ['description' => 'Texto longo do atendimento', 'password' => 'segredo'])
            ->assertRedirect('/pacientes')
            ->assertSessionMissing('_old_input')
            ->assertSessionHas('warning');
    }

    public function test_json_requests_still_get_a_plain_419(): void
    {
        Route::post('/_teste/formulario', fn () => throw new TokenMismatchException)->middleware('web');

        $this->postJson('/_teste/formulario')->assertStatus(419);
    }
}
