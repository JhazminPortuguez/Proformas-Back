<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Validation\Rules\Password;

/**
 * Ronda C: una sola regla de contraseña para el registro del portal,
 * "Cambiar contraseña" y "Olvidé mi contraseña": al menos 8 caracteres, con
 * letras y números. Las cuentas existentes no se tocan; la regla vale al
 * elegir una contraseña.
 */
final class ReglaPassword
{
    /** @return list<mixed> */
    public static function reglas(): array
    {
        return ['required', 'string', 'max:255', 'confirmed', Password::min(8)->letters()->numbers()];
    }

    /**
     * Mensajes en español. `$que` es como se nombra el campo: "La contraseña"
     * o "La contraseña nueva".
     *
     * @return array<string, string>
     */
    public static function mensajes(string $campo = 'password', string $que = 'La contraseña'): array
    {
        $minuscula = mb_strtolower(mb_substr($que, 0, 1)).mb_substr($que, 1);

        return [
            "{$campo}.required" => "Escribe {$minuscula}.",
            "{$campo}.confirmed" => "La confirmación no coincide con {$minuscula}.",
            "{$campo}.min" => "{$que} debe tener al menos 8 caracteres.",
            "{$campo}.letters" => "{$que} debe tener al menos una letra.",
            "{$campo}.numbers" => "{$que} debe tener al menos un número.",
            "{$campo}.max" => "{$que} admite como máximo 255 caracteres.",
        ];
    }
}
