<?php

namespace Tests\Feature;

use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    public function test_responses_include_security_headers(): void
    {
        $response = $this->get('/login');

        $response->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('Cross-Origin-Opener-Policy', 'same-origin')
            ->assertHeaderMissing('X-Powered-By');
    }

    public function test_content_security_policy_is_restrictive_and_uses_a_nonce(): void
    {
        $csp = (string) $this->get('/login')->headers->get('Content-Security-Policy');

        $this->assertStringContainsString("default-src 'self'", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
        $this->assertMatchesRegularExpression("/script-src 'self' 'nonce-[A-Za-z0-9]{40}'/", $csp);
        $this->assertStringNotContainsString('unsafe-inline', $csp);
        $this->assertStringNotContainsString('unsafe-eval', $csp);
    }

    public function test_content_security_policy_nonce_changes_on_every_request(): void
    {
        $first = $this->get('/login')->headers->get('Content-Security-Policy');
        $second = $this->get('/login')->headers->get('Content-Security-Policy');

        $this->assertNotSame($first, $second);
    }

    public function test_hsts_is_sent_only_over_https(): void
    {
        $this->get('http://localhost/login')->assertHeaderMissing('Strict-Transport-Security');

        $this->get('https://localhost/login')
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }

    public function test_health_endpoint_also_receives_security_headers(): void
    {
        $this->get('/up')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
    }
}
