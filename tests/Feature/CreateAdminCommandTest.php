<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_an_active_admin(): void
    {
        $this->artisan('clinic:create-admin')
            ->expectsQuestion('Nome completo', '  Ana Administradora ')
            ->expectsQuestion('E-mail', ' Ana@Example.com ')
            ->expectsQuestion('Senha', 'Admin#Senha-Forte2026')
            ->expectsQuestion('Confirme a senha', 'Admin#Senha-Forte2026')
            ->assertSuccessful();

        $admin = User::where('email', 'ana@example.com')->firstOrFail();

        $this->assertSame('Ana Administradora', $admin->name);
        $this->assertTrue($admin->isAdmin());
        $this->assertTrue($admin->is_active);
        $this->assertFalse($admin->mustChangePassword());
        $this->assertTrue(Hash::check('Admin#Senha-Forte2026', $admin->password));
    }

    public function test_it_fails_when_the_confirmation_does_not_match(): void
    {
        $this->artisan('clinic:create-admin')
            ->expectsQuestion('Nome completo', 'Ana Administradora')
            ->expectsQuestion('E-mail', 'ana@example.com')
            ->expectsQuestion('Senha', 'Admin#Senha-Forte2026')
            ->expectsQuestion('Confirme a senha', 'Outra#Senha-Forte2026')
            ->assertFailed();

        $this->assertSame(0, User::count());
    }
}
