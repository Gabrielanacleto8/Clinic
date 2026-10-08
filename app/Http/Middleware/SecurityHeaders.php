<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    /**
     * Aplica cabeçalhos de segurança em todas as respostas.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Gerado antes da view para que @vite e os templates usem o mesmo nonce.
        $nonce = Vite::useCspNonce();

        $response = $next($request);

        $response->headers->set('Content-Security-Policy', $this->contentSecurityPolicy($nonce));
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');

        // Páginas autenticadas exibem dados de pacientes: não devem ficar em
        // cache do navegador nem reaparecer pelo botão "voltar" após o logout.
        if ($request->user()) {
            $response->headers->set('Cache-Control', 'no-store, private');
        }

        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        $response->headers->remove('X-Powered-By');

        if (! headers_sent()) {
            header_remove('X-Powered-By');
        }

        return $response;
    }

    private function contentSecurityPolicy(string $nonce): string
    {
        $script = ["'self'", "'nonce-{$nonce}'"];
        $style = ["'self'", "'nonce-{$nonce}'"];
        $connect = ["'self'"];

        // Apenas em desenvolvimento: libera o servidor do Vite (HMR).
        if (app()->isLocal() && Vite::isRunningHot()) {
            $devServer = rtrim((string) file_get_contents(Vite::hotFile()));
            $webSocket = preg_replace('/^http/', 'ws', $devServer);

            $script[] = $devServer;
            // O Vite injeta <style> sem nonce no HMR; 'unsafe-inline' só vale sem nonce.
            $style = ["'self'", "'unsafe-inline'", $devServer];
            $connect = [...$connect, $devServer, $webSocket];
        }

        return implode('; ', [
            "default-src 'self'",
            'script-src '.implode(' ', $script),
            'style-src '.implode(' ', $style),
            "img-src 'self' data:",
            "font-src 'self'",
            'connect-src '.implode(' ', $connect),
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
        ]);
    }
}
