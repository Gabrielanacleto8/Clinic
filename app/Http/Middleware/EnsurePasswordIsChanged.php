<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePasswordIsChanged
{
    /**
     * Quem está com senha provisória só acessa o sistema depois de trocá-la.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->mustChangePassword()) {
            return redirect()->route('password.edit')
                ->with('warning', 'Defina uma nova senha para continuar.');
        }

        return $next($request);
    }
}
