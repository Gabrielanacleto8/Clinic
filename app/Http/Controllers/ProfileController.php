<?php

namespace App\Http\Controllers;

use App\Enums\AuditAction;
use App\Enums\Permission;
use App\Models\AuditLog;
use App\Services\RolePermissions;
use App\Support\Theme;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Página da própria conta: dados, acessos recentes, o que o perfil permite,
 * aparência e troca de senha. Cada um só vê a si mesmo; os dados são
 * alterados pelo administrador em Usuários.
 */
class ProfileController extends Controller
{
    private const RECENT_LOGINS = 5;

    public function show(Request $request, RolePermissions $permissions): View
    {
        $user = $request->user();

        // Entradas recentes na conta, para a pessoa notar um acesso que não reconhece.
        $logins = AuditLog::query()
            ->where('user_id', $user->getKey())
            ->where('action', AuditAction::LoginSucceeded)
            ->latest('id')
            ->limit(self::RECENT_LOGINS)
            ->get(['id', 'ip_address', 'user_agent', 'created_at']);

        $granted = $user->isAdmin()
            ? []
            : collect(Permission::cases())
                ->filter(fn (Permission $permission) => $permissions->allows($user->role, $permission))
                ->groupBy(fn (Permission $permission) => $permission->module());

        return view('profile.show', [
            'user' => $user,
            'logins' => $logins,
            'granted' => $granted,
            'theme' => Theme::current($request),
        ]);
    }

    /**
     * Aparência deste aparelho. O botão do topo faz o mesmo pelo app.js, sem recarregar.
     */
    public function updateTheme(Request $request): RedirectResponse
    {
        $theme = $request->validate(['theme' => ['required', Rule::in(array_keys(Theme::OPTIONS))]])['theme'];

        return redirect()->route('profile.show')
            ->with('success', 'Aparência atualizada neste aparelho.')
            ->withCookie(cookie()->forever(Theme::COOKIE, $theme, httpOnly: false));
    }
}
