<?php

use App\Http\Middleware\EnsurePasswordIsChanged;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(SecurityHeaders::class);

        $middleware->alias([
            'active' => EnsureUserIsActive::class,
            'password.changed' => EnsurePasswordIsChanged::class,
        ]);

        $middleware->redirectUsersTo('/');

        // Preferência visual gravada pelo JavaScript (barra lateral recolhida).
        // Não é sensível e o servidor só a compara com um valor fixo.
        $middleware->encryptCookies(except: ['sidebar']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Envio maior que o post_max_size do PHP: o corpo chega vazio, então
        // devolve o usuário à tela com uma mensagem em vez de um erro 413 cru.
        $exceptions->render(function (PostTooLargeException $exception, Request $request) {
            return $request->expectsJson()
                ? null
                : back()->with('error', 'O envio é grande demais para o servidor. Tente enviar menos fotos por vez.');
        });

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
