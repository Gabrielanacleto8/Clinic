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
     * Com vírgula, os pontos são separadores de milhar. Sem vírgula, o ponto é
     * aceito como decimal. O que não for número volta como veio, para a
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
        }

        return preg_match('/^\d+(\.\d+)?$/', $candidate) === 1 ? $candidate : $value;
    }

    public static function money(int|float|string|null $value): string
    {
        return $value === null ? '' : 'R$ '.number_format((float) $value, 2, ',', '.');
    }

    /**
     * Valor monetário para preencher um campo de formulário (sem o "R$").
     */
    public static function moneyInput(int|float|string|null $value): ?string
    {
        return $value === null ? null : number_format((float) $value, 2, ',', '');
    }

    /**
     * Quantidade com até três casas, sem zeros à direita (2,5 em vez de 2,500).
     */
    public static function quantity(int|float|string|null $value): string
    {
        if ($value === null) {
            return '';
        }

        $formatted = number_format((float) $value, 3, ',', '.');

        return rtrim(rtrim($formatted, '0'), ',');
    }
}
