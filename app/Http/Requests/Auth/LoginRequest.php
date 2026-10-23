<?php

namespace App\Http\Requests\Auth;

use App\Enums\AuditAction;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    private const MAX_ATTEMPTS = 5;

    private const LOCKOUT_SECONDS = 60;

    /**
     * Segunda barreira, por e-mail de qualquer origem: muitas falhas seguidas
     * travam a conta por mais tempo, mesmo com o atacante trocando de IP.
     */
    private const ACCOUNT_MAX_ATTEMPTS = 10;

    private const ACCOUNT_LOCKOUT_SECONDS = 900;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
            'remember' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['email' => 'e-mail', 'password' => 'senha'];
    }

    /**
     * @throws ValidationException
     */
    public function authenticate(AuditLogger $audit): void
    {
        $this->ensureIsNotRateLimited($audit);

        // Usuário inexistente, senha errada e conta desativada recebem a mesma
        // resposta, para não revelar quais e-mails estão cadastrados.
        $credentials = [
            'email' => $this->string('email')->toString(),
            'password' => $this->string('password')->toString(),
            'is_active' => true,
        ];

        if (! Auth::attempt($credentials, $this->boolean('remember'))) {
            RateLimiter::hit($this->throttleKey(), self::LOCKOUT_SECONDS);
            RateLimiter::hit(self::accountKey($credentials['email']), self::ACCOUNT_LOCKOUT_SECONDS);

            $audit->log(AuditAction::LoginFailed, User::where('email', $credentials['email'])->first());

            throw ValidationException::withMessages(['email' => trans('auth.failed')]);
        }

        RateLimiter::clear($this->throttleKey());
        RateLimiter::clear(self::accountKey($credentials['email']));
    }

    /**
     * Libera o e-mail travado por falhas seguidas (usado ao redefinir a senha).
     */
    public static function clearLockout(string $email): void
    {
        RateLimiter::clear(self::accountKey($email));
    }

    /**
     * @throws ValidationException
     */
    private function ensureIsNotRateLimited(AuditLogger $audit): void
    {
        $accountKey = self::accountKey($this->string('email')->toString());

        if (RateLimiter::tooManyAttempts($accountKey, self::ACCOUNT_MAX_ATTEMPTS)) {
            $audit->log(AuditAction::LoginLockedOut, null, ['scope' => 'account']);

            throw ValidationException::withMessages([
                'email' => 'Acesso bloqueado por excesso de tentativas. Tente de novo em '
                    .(int) ceil(RateLimiter::availableIn($accountKey) / 60).' minuto(s) ou peça ao administrador uma nova senha.',
            ]);
        }

        if (! RateLimiter::tooManyAttempts($this->throttleKey(), self::MAX_ATTEMPTS)) {
            return;
        }

        $audit->log(AuditAction::LoginLockedOut);

        throw ValidationException::withMessages([
            'email' => trans('auth.throttle', ['seconds' => RateLimiter::availableIn($this->throttleKey())]),
        ]);
    }

    /**
     * Chave por e-mail, sem o e-mail em claro no cache.
     */
    private static function accountKey(string $email): string
    {
        return 'login-account:'.hash('sha256', Str::lower(trim($email)));
    }

    private function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->string('email')->toString()).'|'.$this->ip());
    }
}
