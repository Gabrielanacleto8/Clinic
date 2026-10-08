<?php

namespace App\Http\Controllers\Auth;

use App\Enums\AuditAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class LoginController extends Controller
{
    public function create(): View
    {
        return view('auth.login');
    }

    public function store(LoginRequest $request, AuditLogger $audit): RedirectResponse
    {
        $request->authenticate($audit);

        // Novo ID de sessão após autenticar, contra fixação de sessão.
        $request->session()->regenerate();

        $audit->log(AuditAction::LoginSucceeded, $request->user(), $request->boolean('remember') ? ['remember' => true] : []);

        return redirect()->intended(route('home'));
    }

    public function destroy(Request $request, AuditLogger $audit): RedirectResponse
    {
        $audit->log(AuditAction::Logout, $request->user());

        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
