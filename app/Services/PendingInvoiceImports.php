<?php

namespace App\Services;

use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Str;

/**
 * Notas fiscais lidas e ainda em conferência, guardadas na sessão do
 * usuário. Cada nota fica sob um token próprio, levado pelo formulário de
 * conferência: duas abas abertas não misturam notas.
 */
class PendingInvoiceImports
{
    private const SESSION_KEY = 'stock.invoice_imports';

    /**
     * Conferências guardadas ao mesmo tempo. As mais antigas saem da sessão
     * quando o limite é passado.
     */
    private const MAX_PENDING = 3;

    /**
     * @param  array<string, mixed>  $invoice
     */
    public function put(Session $session, array $invoice): string
    {
        $token = Str::random(32);
        $pending = [...$this->all($session), $token => $invoice];
        $session->put(self::SESSION_KEY, array_slice($pending, -self::MAX_PENDING, preserve_keys: true));

        return $token;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(Session $session, mixed $token): ?array
    {
        return is_string($token) ? ($this->all($session)[$token] ?? null) : null;
    }

    public function forget(Session $session, mixed $token): void
    {
        if (! is_string($token)) {
            return;
        }

        $pending = $this->all($session);
        unset($pending[$token]);

        $session->put(self::SESSION_KEY, $pending);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function all(Session $session): array
    {
        $pending = $session->get(self::SESSION_KEY, []);

        return is_array($pending) ? $pending : [];
    }
}
