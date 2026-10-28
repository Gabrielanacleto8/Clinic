<?php

namespace App\Support;

/**
 * Resumo legível do navegador e do sistema a partir do user agent, como
 * "Chrome no Android". Aproximado de propósito: serve para a pessoa
 * reconhecer os próprios aparelhos.
 */
final class DeviceName
{
    private const BROWSERS = ['Edg/' => 'Edge', 'OPR/' => 'Opera', 'SamsungBrowser' => 'Samsung Internet', 'Firefox/' => 'Firefox', 'Chrome/' => 'Chrome', 'Safari/' => 'Safari'];

    private const SYSTEMS = ['Android' => 'Android', 'iPhone' => 'iPhone', 'iPad' => 'iPad', 'Windows' => 'Windows', 'Mac OS X' => 'Mac', 'Linux' => 'Linux'];

    public static function from(?string $userAgent): string
    {
        $userAgent = (string) $userAgent;
        $browser = self::first(self::BROWSERS, $userAgent);
        $system = self::first(self::SYSTEMS, $userAgent);

        return match (true) {
            $browser !== null && $system !== null => "{$browser} no {$system}",
            $browser !== null || $system !== null => (string) ($browser ?? $system),
            default => 'Navegador não identificado',
        };
    }

    /**
     * @param  array<string, string>  $candidates  Trecho do user agent => nome, na ordem de prioridade.
     */
    private static function first(array $candidates, string $userAgent): ?string
    {
        foreach ($candidates as $needle => $name) {
            if (str_contains($userAgent, $needle)) {
                return $name;
            }
        }

        return null;
    }
}
