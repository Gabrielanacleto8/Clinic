<?php

namespace Tests\Unit;

use App\Support\LocalizedDecimal;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class LocalizedDecimalTest extends TestCase
{
    /**
     * @return array<string, array{mixed, mixed}>
     */
    public static function inputs(): array
    {
        return [
            'vírgula decimal' => ['2,5', '2.5'],
            'milhar e vírgula' => ['1.234,56', '1234.56'],
            'moeda com espaço' => ['R$ 1.234,56', '1234.56'],
            'milhar sem vírgula' => ['1.000', '1000'],
            'dezena de milhar' => ['12.500', '12500'],
            'milhão' => ['1.000.000', '1000000'],
            'ponto decimal curto' => ['1.5', '1.5'],
            'ponto decimal de duas casas' => ['2.25', '2.25'],
            'zero com ponto' => ['0.500', '0.500'],
            'inteiro' => ['100', '100'],
            'grupo incompleto continua decimal' => ['1.0001', '1.0001'],
            'texto volta como veio' => ['abc', 'abc'],
            'vazio volta como veio' => ['', ''],
            'nulo volta como veio' => [null, null],
        ];
    }

    #[DataProvider('inputs')]
    public function test_normalize_reads_the_brazilian_format(mixed $input, mixed $expected): void
    {
        $this->assertSame($expected, LocalizedDecimal::normalize($input));
    }

    public function test_form_values_round_trip_without_thousand_separators(): void
    {
        foreach (['1000', '1000.5', '12500', '0.085'] as $value) {
            $this->assertSame((float) $value, (float) LocalizedDecimal::normalize(LocalizedDecimal::quantityInput($value)));
        }

        // A exibição usa milhar, e mesmo ela volta ao valor certo.
        $this->assertSame('1.000', LocalizedDecimal::quantity('1000'));
        $this->assertSame('1000', LocalizedDecimal::normalize(LocalizedDecimal::quantity('1000')));
    }

    public function test_unit_money_keeps_fractions_of_a_cent(): void
    {
        $this->assertSame('R$ 0,085', LocalizedDecimal::unitMoney('0.0850'));
        $this->assertSame('R$ 950,50', LocalizedDecimal::unitMoney('950.5000'));
        $this->assertSame('R$ 1.234,5678', LocalizedDecimal::unitMoney('1234.5678'));
        $this->assertSame('', LocalizedDecimal::unitMoney(null));
    }
}
