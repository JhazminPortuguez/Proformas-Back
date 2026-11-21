<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\Rol;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ronda A, parte 3: un cliente sin el correo verificado entra y ve su portal,
 * pero no envía solicitudes ni acepta o rechaza proformas. El personal
 * (admin y operario) no verifica: lo crea el administrador.
 */
class EnsureCorreoVerificado
{
    public const MENSAJE = 'Verifica tu correo para enviar solicitudes y responder proformas. Revisa tu bandeja de entrada o pide un enlace nuevo con "Reenviar enlace".';

    public function handle(Request $request, Closure $next): Response
    {
        $usuario = $request->user();

        if ($usuario !== null && $usuario->rol === Rol::Cliente && ! $usuario->hasVerifiedEmail()) {
            return response()->json(['message' => self::MENSAJE, 'codigo' => 'correo_no_verificado'], 403);
        }

        return $next($request);
    }
}
