<?php

declare(strict_types=1);

namespace App\Rules;

use App\Support\OperarioAsignable;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class OperarioAsignableRule implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
            return; // La regla "integer" reporta el formato inválido.
        }

        $error = OperarioAsignable::errorPara((int) $value);

        if ($error !== null) {
            $fail($error);
        }
    }
}
