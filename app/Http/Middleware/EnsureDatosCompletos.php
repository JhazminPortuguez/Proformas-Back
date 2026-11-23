<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\Rol;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ronda B, parte 2: una cuenta creada con Google completa su teléfono y su
 * NIT/CI antes de enviar solicitudes (PUT /portal/mis-datos).
 */
class EnsureDatosCompletos
{
    public const MENSAJE = 'Antes de enviar solicitudes, completa tu teléfono y tu NIT/CI en "Completa tus datos".';

    public function handle(Request $request, Closure $next): Response
    {
        $usuario = $request->user();

        if ($usuario !== null && $usuario->rol === Rol::Cliente && $usuario->datos_pendientes) {
            return response()->json(['message' => self::MENSAJE, 'codigo' => 'datos_pendientes'], 403);
        }

        return $next($request);
    }
}
