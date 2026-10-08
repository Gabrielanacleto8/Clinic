<?php

namespace App\Console\Commands;

use App\Enums\AuditAction;
use App\Enums\UserRole;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

#[Signature('clinic:create-admin')]
#[Description('Cria um usuário administrador de forma interativa')]
class CreateAdmin extends Command
{
    public function handle(AuditLogger $audit): int
    {
        if (! $this->input->isInteractive()) {
            $this->components->error('Este comando precisa de um terminal interativo, pois a senha é digitada de forma oculta.');

            return self::FAILURE;
        }

        // No console não há o middleware TrimStrings das requisições web.
        $name = trim(text('Nome completo', required: true, validate: $this->validator('name', ['string', 'min:3', 'max:255'])));

        $email = strtolower(trim(text(
            'E-mail',
            required: true,
            validate: $this->validator('email', ['string', 'email', 'max:255', Rule::unique(User::class)]),
        )));

        // A senha nunca é aceita por argumento, para não ficar no histórico do shell.
        $password = password('Senha', required: true, validate: $this->validator('password', [Password::defaults()]));

        if (password('Confirme a senha', required: true) !== $password) {
            $this->components->error('As senhas não conferem.');

            return self::FAILURE;
        }

        $user = new User(['name' => $name, 'email' => $email, 'password' => $password]);
        $user->role = UserRole::Admin;
        $user->is_active = true;
        $user->password_changed_at = now();
        $user->save();

        $audit->log(AuditAction::UserCreated, $user, ['role' => $user->role->value, 'via' => 'console']);

        $this->components->info("Administrador {$email} criado.");

        return self::SUCCESS;
    }

    /**
     * @param  list<mixed>  $rules
     */
    private function validator(string $field, array $rules): \Closure
    {
        return fn (string $value) => Validator::make([$field => trim($value)], [$field => $rules])->errors()->first($field) ?: null;
    }
}
