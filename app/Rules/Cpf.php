<?php

namespace App\Rules;

use App\Support\Cpf as CpfSupport;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class Cpf implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! CpfSupport::isValid($value)) {
            $fail('O CPF informado é inválido.');
        }
    }
}
