<?php

use App\Http\Middleware\EnsurePasswordIsChanged;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\SecurityHeaders;
use App\Support\SanitizedQueryReport;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

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
        $middleware->encryptCookies(except: ['sidebar', 'theme']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Envio maior que o post_max_size do PHP: o corpo chega vazio, então
        // devolve o usuário à tela com uma mensagem em vez de um erro 413 cru.
        $exceptions->render(function (PostTooLargeException $exception, Request $request) {
            return $request->expectsJson()
                ? null
                : back()->with('error', 'O envio é grande demais para o servidor. Tente enviar menos fotos por vez.');
        });

        // Formulário enviado depois que a sessão expirou (erro 419). Com o
        // "Lembrar de mim" o usuário continua conectado e volta à tela; sem ele,
        // vai ao login com a explicação, em vez da página crua. O que foi
        // digitado não é reposto: num computador compartilhado, a aba antiga
        // de outra pessoa devolveria o texto clínico dela a quem está conectado.
        // Formulários longos renovam a sessão enquanto se digita (data-keepalive).
        $exceptions->render(function (HttpException $exception, Request $request) {
            if ($exception->getStatusCode() !== 419 || $request->expectsJson()) {
                return null;
            }

            if ($request->routeIs('login')) {
                return redirect()->route('login')
                    ->withInput($request->only('email'))
                    ->with('warning', 'A página de acesso ficou aberta por muito tempo. Entre de novo.');
            }

            if ($request->user()) {
                return back()
                    ->with('warning', 'A página ficou aberta por muito tempo e o envio não foi aceito. Preencha de novo e envie.');
            }

            return redirect()->guest(route('login'))
                ->with('warning', 'Sua sessão expirou por inatividade. Entre de novo; o que não foi salvo precisa ser enviado outra vez.');
        });

        // Erro de banco pode carregar dados de paciente na mensagem (valor
        // duplicado, valor longo demais). Vai para o log só o que identifica a
        // falha, nunca os valores. Ver SanitizedQueryReport.
        $exceptions->report(function (QueryException $exception) {
            SanitizedQueryReport::log($exception);

            return false;
        });

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
