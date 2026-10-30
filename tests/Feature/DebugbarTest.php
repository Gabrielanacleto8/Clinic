<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DebugbarTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Relê config/debugbar.php com as variáveis de ambiente indicadas.
     *
     * @param  array<string, string>  $env
     * @return array<string, mixed>
     */
    private function configWith(array $env): array
    {
        $previous = [];

        foreach ($env as $key => $value) {
            $previous[$key] = [$_ENV[$key] ?? null, $_SERVER[$key] ?? null];
            $_ENV[$key] = $_SERVER[$key] = $value;
        }

        try {
            return require config_path('debugbar.php');
        } finally {
            foreach ($previous as $key => [$envValue, $serverValue]) {
                if ($envValue === null) {
                    unset($_ENV[$key]);
                } else {
                    $_ENV[$key] = $envValue;
                }

                if ($serverValue === null) {
                    unset($_SERVER[$key]);
                } else {
                    $_SERVER[$key] = $serverValue;
                }
            }
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function nonLocalEnvironments(): array
    {
        return [
            'produção' => ['production'],
            'testes' => ['testing'],
            'homologação' => ['staging'],
        ];
    }

    #[DataProvider('nonLocalEnvironments')]
    public function test_the_debugbar_cannot_be_turned_on_outside_local_development(string $environment): void
    {
        $config = $this->configWith(['APP_ENV' => $environment, 'DEBUGBAR_ENABLED' => 'true', 'DEBUGBAR_FORCE_ALLOW_ENABLE' => 'true']);

        $this->assertFalse($config['enabled']);
        $this->assertFalse($config['force_allow_enable']);
    }

    public function test_in_local_development_it_follows_the_debug_setting_unless_told_otherwise(): void
    {
        $this->assertNull($this->configWith(['APP_ENV' => 'local'])['enabled']);
        $this->assertFalse($this->configWith(['APP_ENV' => 'local', 'DEBUGBAR_ENABLED' => 'false'])['enabled']);
    }

    public function test_pages_are_served_without_the_debugbar_outside_local_development(): void
    {
        $html = $this->actingAs(User::factory()->admin()->create())->get('/')->assertOk()->getContent();

        $this->assertStringNotContainsString('phpdebugbar', $html);
        $this->assertStringNotContainsString('_debugbar', $html);
        $this->get('/_debugbar/assets?type=js')->assertNotFound();
    }
}
