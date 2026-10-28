<?php

namespace App\Rules;

use App\Support\Cnpj as CnpjSupport;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class Cnpj implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! CnpjSupport::isValid($value)) {
            $fail('O CNPJ informado é inválido.');
        }
    }
}
