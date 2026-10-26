<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\Rol;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Reglas compartidas para elegir al operario de una orden de servicio.
 * Se consulta sin el scope de empresa para poder distinguir "no existe" de
 * "pertenece a otra empresa" en el mensaje de validación.
 */
final class OperarioAsignable
{
    public static function errorPara(int $operarioId): ?string
    {
        $operario = User::query()
            ->withoutGlobalScope('empresa')
            ->find($operarioId);

        return match (true) {
            $operario === null => 'El operario seleccionado no existe.',
            $operario->rol !== Rol::Operario => 'El usuario seleccionado no tiene rol de operario.',
            ! $operario->activo => 'El operario seleccionado está inactivo.',
            (int) $operario->empresa_id !== EmpresaActual::id() => 'El operario pertenece a otra empresa.',
            default => null,
        };
    }

    public static function validar(int $operarioId): User
    {
        $error = self::errorPara($operarioId);

        if ($error !== null) {
            throw ValidationException::withMessages([
                'operario_id' => $error,
            ]);
        }

        return User::query()
            ->withoutGlobalScope('empresa')
            ->findOrFail($operarioId);
    }
}
