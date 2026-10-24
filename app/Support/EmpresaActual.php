<?php

declare(strict_types=1);

namespace App\Support;

use Throwable;

final class EmpresaActual
{
    /**
     * Evita la recursión cuando el global scope de User consulta la empresa
     * mientras el guard de sesión todavía está resolviendo al usuario.
     */
    private static bool $resolviendo = false;

    /**
     * Empresa del usuario autenticado o, para procesos públicos/CLI,
     * la empresa configurada por defecto (una sola empresa por ahora).
     */
    public static function id(): int
    {
        if (self::$resolviendo) {
            return self::desdeSesionODefecto();
        }

        self::$resolviendo = true;

        try {
            $empresaId = auth()->user()?->empresa_id;
        } finally {
            self::$resolviendo = false;
        }

        return (int) ($empresaId ?? self::desdeSesionODefecto());
    }

    private static function desdeSesionODefecto(): int
    {
        try {
            if (app()->bound('session') && session()->has('empresa_id')) {
                return (int) session('empresa_id');
            }
        } catch (Throwable) {
            // Sin sesión disponible (CLI, colas, tests aislados).
        }

        return (int) config('app.empresa_default_id', 1);
    }
}
