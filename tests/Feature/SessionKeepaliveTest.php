<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GET /sessao: renova a sessão enquanto alguém digita um formulário longo.
 * Não devolve nada além do status.
 */
class SessionKeepaliveTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_guest_does_not_keep_a_session_alive(): void
    {
        $this->get('/sessao')->assertRedirect('/login');
        $this->getJson('/sessao')->assertUnauthorized();
    }

    public function test_any_signed_in_profile_keeps_its_session_alive_without_content(): void
    {
        foreach ([User::factory()->create(), User::factory()->professional()->create(), User::factory()->admin()->create()] as $user) {
            $response = $this->actingAs($user)->get('/sessao');

            $response->assertNoContent();
            $this->assertSame('', $response->getContent());
        }
    }

    public function test_a_deactivated_user_is_signed_out_instead(): void
    {
        $user = User::factory()->inactive()->create();

        $this->actingAs($user)->get('/sessao')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_the_keepalive_is_rate_limited(): void
    {
        $this->actingAs(User::factory()->create());

        foreach (range(1, 20) as $attempt) {
            $this->get('/sessao')->assertNoContent();
        }

        $this->get('/sessao')->assertTooManyRequests();
    }
}
