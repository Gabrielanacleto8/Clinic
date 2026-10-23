<?php

namespace App\Support;

/**
 * Números decimais digitados no padrão brasileiro (1.234,56) e exibidos de volta nele.
 */
final class LocalizedDecimal
{
    /**
     * Converte a entrada do usuário para o formato do banco (1234.56).
     *
     * Com vírgula, os pontos são separadores de milhar. Sem vírgula, pontos em
     * grupos de três dígitos depois de um número que não começa por zero
     * também são milhar ("1.000" é mil, "12.500" é doze mil e quinhentos),
     * como se escreve no Brasil; os demais pontos são aceitos como decimal
     * ("0.5", "2.25"). O que não for número volta como veio, para a
     * validação recusar com a mensagem certa.
     */
    public static function normalize(mixed $value): mixed
    {
        if (! is_string($value) || trim($value) === '') {
            return $value;
        }

        $candidate = preg_replace('/[\s\x{00A0}]|R\$/u', '', $value) ?? $value;

        if (str_contains($candidate, ',')) {
            $candidate = str_replace(',', '.', str_replace('.', '', $candidate));
        } elseif (preg_match('/^[1-9]\d{0,2}(\.\d{3})+$/', $candidate) === 1) {
            $candidate = str_replace('.', '', $candidate);
        }

        return preg_match('/^\d+(\.\d+)?$/', $candidate) === 1 ? $candidate : $value;
    }

    public static function money(int|float|string|null $value): string
    {
        return $value === null ? '' : 'R$ '.number_format((float) $value, 2, ',', '.');
    }

    /**
     * Custo por unidade de medida: centavos sempre, e até quatro casas quando
     * o valor tem fração de centavo (R$ 0,085 por agulha).
     */
    public static function unitMoney(int|float|string|null $value): string
    {
        if ($value === null) {
            return '';
        }

        $formatted = number_format((float) $value, 4, ',', '.');

        return 'R$ '.preg_replace('/(,\d{2}\d*?)0+$/', '$1', $formatted);
    }

    /**
     * Valor monetário para preencher um campo de formulário (sem o "R$").
     */
    public static function moneyInput(int|float|string|null $value): ?string
    {
        return $value === null ? null : number_format((float) $value, 2, ',', '');
    }

    /**
     * Custo por unidade para preencher um campo de formulário: centavos
     * sempre, e até quatro casas quando há fração de centavo.
     */
    public static function unitMoneyInput(int|float|string|null $value): ?string
    {
        return $value === null ? null : preg_replace('/(,\d{2}\d*?)0+$/', '$1', number_format((float) $value, 4, ',', ''));
    }

    /**
     * Quantidade com até três casas, sem zeros à direita (2,5 em vez de 2,500).
     */
    public static function quantity(int|float|string|null $value): string
    {
        if ($value === null) {
            return '';
        }

        return self::withoutTrailingZeros(number_format((float) $value, 3, ',', '.'));
    }

    /**
     * Quantidade para preencher um campo de formulário (sem separador de milhar).
     */
    public static function quantityInput(int|float|string|null $value): ?string
    {
        return $value === null ? null : self::withoutTrailingZeros(number_format((float) $value, 3, ',', ''));
    }

    private static function withoutTrailingZeros(string $formatted): string
    {
        return rtrim(rtrim($formatted, '0'), ',');
    }
}
