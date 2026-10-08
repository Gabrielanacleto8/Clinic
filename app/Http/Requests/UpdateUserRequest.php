<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('user'));
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => strtolower($this->input('email'))]);
        }

        // Checkbox desmarcado não é enviado pelo navegador.
        $this->merge(['is_active' => $this->boolean('is_active')]);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:3', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique(User::class)->ignore($this->route('user'))],
            'role' => ['required', Rule::enum(UserRole::class)],
            'is_active' => ['required', 'boolean'],
            'password' => ['nullable', 'string', 'max:255', 'confirmed', Password::defaults()],
        ];
    }

    /**
     * Um administrador não pode rebaixar nem desativar a própria conta.
     * Isso garante que sempre reste ao menos um administrador ativo.
     *
     * @return list<callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                /** @var User $target */
                $target = $this->route('user');

                if (! $target->is($this->user())) {
                    return;
                }

                if ($this->input('role') !== $target->role->value) {
                    $validator->errors()->add('role', 'Você não pode alterar o próprio perfil.');
                }

                if (! $this->boolean('is_active')) {
                    $validator->errors()->add('is_active', 'Você não pode desativar a própria conta.');
                }

                if ($this->filled('password')) {
                    $validator->errors()->add('password', 'Para trocar a sua senha, clique no seu nome, no menu lateral.');
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'nome', 'email' => 'e-mail', 'role' => 'perfil',
            'is_active' => 'situação', 'password' => 'senha provisória',
        ];
    }
}
