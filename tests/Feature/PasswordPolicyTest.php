<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PasswordPolicyTest extends TestCase
{
    #[DataProvider('weakPasswords')]
    public function test_weak_passwords_are_rejected(string $password): void
    {
        $this->assertTrue($this->validate($password)->fails());
    }

    #[DataProvider('acceptedPasswords')]
    public function test_passwords_within_the_policy_are_accepted(string $password): void
    {
        $this->assertTrue($this->validate($password)->passes());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function acceptedPasswords(): array
    {
        return [
            'exatamente 8 caracteres, sem símbolo' => ['Senha123'],
            'longa, sem símbolo' => ['SenhaSemSimbolo123'],
            'com símbolo' => ['Cl1nic@-Segura#2026'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function weakPasswords(): array
    {
        return [
            '7 caracteres' => ['Senha12'],
            'sem número' => ['SenhaSemNumero'],
            'sem maiúscula' => ['senhaminuscula1'],
            'sem minúscula' => ['SENHAMAIUSCULA1'],
            'só números' => ['1234567890'],
        ];
    }

    private function validate(string $password): \Illuminate\Validation\Validator
    {
        return Validator::make(['password' => $password], ['password' => Password::defaults()]);
    }
}
