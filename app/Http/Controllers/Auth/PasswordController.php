<?php

namespace App\Http\Controllers\Auth;

use App\Enums\AuditAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\UpdatePasswordRequest;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class PasswordController extends Controller
{
    public function edit(): View
    {
        return view('auth.password');
    }

    public function update(UpdatePasswordRequest $request, AuditLogger $audit): RedirectResponse
    {
        $user = $request->user();

        // O middleware auth.session derruba as demais sessões deste usuário,
        // pois o hash da senha guardado nelas deixa de conferir.
        $user->forceFill([
            'password' => $request->validated('password'),
            'password_changed_at' => now(),
        ])->save();

        $audit->log(AuditAction::PasswordChanged, $user);

        return redirect()->route('home')->with('success', 'Senha alterada com sucesso.');
    }
}
