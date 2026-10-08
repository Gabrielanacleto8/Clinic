<?php

namespace Tests\Unit;

use App\Support\Cpf;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CpfTest extends TestCase
{
    #[DataProvider('validCpfs')]
    public function test_valid_cpfs_are_accepted(string $cpf): void
    {
        $this->assertTrue(Cpf::isValid($cpf));
    }

    #[DataProvider('invalidCpfs')]
    public function test_invalid_cpfs_are_rejected(?string $cpf): void
    {
        $this->assertFalse(Cpf::isValid($cpf));
    }

    public function test_formatting_and_masking(): void
    {
        $this->assertSame('52998224725', Cpf::digits('529.982.247-25'));
        $this->assertSame('529.982.247-25', Cpf::format('52998224725'));
        $this->assertSame('***.982.247-**', Cpf::mask('52998224725'));
        $this->assertSame('', Cpf::mask('123'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function validCpfs(): array
    {
        return [
            'apenas dígitos' => ['52998224725'],
            'formatado' => ['529.982.247-25'],
            'dígito verificador zero' => ['111.444.777-35'],
        ];
    }

    /**
     * @return array<string, array{?string}>
     */
    public static function invalidCpfs(): array
    {
        return [
            'nulo' => [null],
            'vazio' => [''],
            'curto' => ['5299822472'],
            'longo' => ['529982247250'],
            'dígito verificador errado' => ['52998224724'],
            'sequência repetida' => ['11111111111'],
            'zeros' => ['000.000.000-00'],
        ];
    }
}
