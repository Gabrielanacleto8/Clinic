<?php

namespace Tests\Feature\Auth;

use App\Enums\AuditAction;
use App\Models\User;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PasswordTest extends TestCase
{
    use RefreshDatabase;

    private const NEW_PASSWORD = 'Nova-Senha#Forte2026';

    public function test_user_with_temporary_password_is_forced_to_change_it(): void
    {
        $user = User::factory()->withTemporaryPassword()->create();

        $this->actingAs($user)->get('/pacientes')->assertRedirect('/senha');
        $this->actingAs($user)->get('/usuarios')->assertRedirect('/senha');
        $this->actingAs($user)->get('/senha')->assertOk();
    }

    public function test_user_can_change_password(): void
    {
        $user = User::factory()->withTemporaryPassword()->create();

        $this->actingAs($user)->put('/senha', [
            'current_password' => UserFactory::PASSWORD,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertRedirect(route('home'))->assertSessionHasNoErrors();

        $user->refresh();

        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $user->password));
        $this->assertFalse($user->mustChangePassword());
        $this->assertDatabaseHas('audit_logs', ['action' => AuditAction::PasswordChanged->value, 'user_id' => $user->id]);

        $this->get('/pacientes')->assertOk();
    }

    public function test_current_password_must_be_correct(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->put('/senha', [
            'current_password' => 'senha-errada',
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertSessionHasErrors('current_password');

        $this->assertTrue(Hash::check(UserFactory::PASSWORD, $user->refresh()->password));
    }

    public function test_new_password_must_follow_the_policy(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->put('/senha', [
            'current_password' => UserFactory::PASSWORD,
            'password' => 'fraca123',
            'password_confirmation' => 'fraca123',
        ])->assertSessionHasErrors('password');
    }

    public function test_eight_character_password_without_symbols_is_accepted(): void
    {
        $user = User::factory()->withTemporaryPassword()->create();

        $this->actingAs($user)->put('/senha', [
            'current_password' => UserFactory::PASSWORD,
            'password' => 'Clinica8',
            'password_confirmation' => 'Clinica8',
        ])->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check('Clinica8', $user->refresh()->password));
    }

    public function test_seven_character_password_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->put('/senha', [
            'current_password' => UserFactory::PASSWORD,
            'password' => 'Clinic7',
            'password_confirmation' => 'Clinic7',
        ])->assertSessionHasErrors('password');
    }

    public function test_new_password_must_be_confirmed(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->put('/senha', [
            'current_password' => UserFactory::PASSWORD,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => 'Diferente#Senha2026',
        ])->assertSessionHasErrors('password');
    }

    public function test_new_password_must_differ_from_the_current_one(): void
    {
        $user = User::factory()->withTemporaryPassword()->create();

        $this->actingAs($user)->put('/senha', [
            'current_password' => UserFactory::PASSWORD,
            'password' => UserFactory::PASSWORD,
            'password_confirmation' => UserFactory::PASSWORD,
        ])->assertSessionHasErrors('password');

        $this->assertTrue($user->refresh()->mustChangePassword());
    }
}
