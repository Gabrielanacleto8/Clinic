<?php

namespace App\Console\Commands;

use App\Enums\AuditAction;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password;

#[Signature('clinic:reset-password {email : E-mail do usuário}')]
#[Description('Define uma senha provisória para um usuário, como o administrador que esqueceu a própria senha')]
class ResetPassword extends Command
{
    public function handle(AuditLogger $audit): int
    {
        if (! $this->input->isInteractive()) {
            $this->components->error('Este comando precisa de um terminal interativo, pois a senha é digitada de forma oculta.');

            return self::FAILURE;
        }

        $user = User::query()->where('email', strtolower(trim((string) $this->argument('email'))))->first();

        if ($user === null) {
            $this->components->error('Nenhum usuário com esse e-mail.');

            return self::FAILURE;
        }

        // A senha nunca é aceita por argumento, para não ficar no histórico do shell.
        $password = password(
            'Senha provisória',
            required: true,
            validate: fn (string $value) => Validator::make(['password' => $value], ['password' => [Password::defaults()]])->errors()->first('password') ?: null,
        );

        if (password('Confirme a senha', required: true) !== $password) {
            $this->components->error('As senhas não conferem.');

            return self::FAILURE;
        }

        // Senha provisória: a troca é exigida no próximo acesso. A nova senha
        // derruba as sessões abertas (auth.session), e o novo remember_token
        // invalida o "Lembrar de mim" de todos os dispositivos.
        $user->password = $password;
        $user->password_changed_at = null;
        $user->setRememberToken(Str::random(60));
        $user->save();

        LoginRequest::clearLockout($user->email);

        $audit->log(AuditAction::UserPasswordReset, $user, ['via' => 'console']);

        $this->components->info('Senha provisória definida. A troca será exigida no próximo acesso.');

        if (! $user->is_active) {
            $this->components->warn('Este usuário está desativado e só conseguirá entrar depois de reativado.');
        }

        return self::SUCCESS;
    }
}
