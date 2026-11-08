<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\Rol;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Portal del cliente: solo entra un usuario con rol cliente vinculado a un
 * cliente. El admin y el operario no usan el portal.
 */
class EnsureUserIsCliente
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || $user->rol !== Rol::Cliente || $user->cliente_id === null) {
            return response()->json(['message' => 'No tienes permisos para realizar esta acción.'], 403);
        }

        return $next($request);
    }
}
