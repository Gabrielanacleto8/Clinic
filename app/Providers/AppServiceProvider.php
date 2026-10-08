<?php

namespace App\Providers;

use App\Models\User;
use App\Services\RolePermissions;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Uma instância por requisição: as permissões são lidas do banco uma única vez.
        $this->app->scoped(RolePermissions::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $isProduction = $this->app->isProduction();

        // Fora de produção, falha cedo em lazy loading (N+1), atributos
        // inexistentes e mass assignment silenciosamente descartado.
        Model::shouldBeStrict(! $isProduction);

        // Bloqueia migrate:fresh, db:wipe e similares em produção.
        DB::prohibitDestructiveCommands($isProduction);

        Date::use(CarbonImmutable::class);

        if ($isProduction) {
            URL::forceHttps();
        }

        // Definir o que cada perfil acessa é exclusivo do administrador e não é delegável.
        Gate::define('manage-permissions', fn (User $user) => $user->isAdmin());

        // Teto por IP no login, somado ao bloqueio por e-mail + IP do LoginRequest.
        // Freia quem testa uma mesma senha contra muitos e-mails diferentes.
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(20)->by($request->ip()));

        Password::defaults(function () use ($isProduction) {
            // Mínimo de 8 caracteres, com maiúscula, minúscula e número. Símbolos são opcionais.
            $rule = Password::min(8)->letters()->mixedCase()->numbers();

            // Consulta vazamentos conhecidos (k-anonymity) apenas em produção.
            return $isProduction ? $rule->uncompromised() : $rule;
        });
    }
}
