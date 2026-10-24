<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsActive
{
    /**
     * Corta el acceso de cuentas desactivadas aunque su sesión siga viva.
     * No se llama a Auth::logout() aquí para no perder el mensaje de dominio;
     * se eliminan las sesiones persistidas y el guard se invalida en la
     * siguiente petición.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && ! $user->activo) {
            if ($request->hasSession()) {
                DB::table('sessions')
                    ->where('user_id', $user->getKey())
                    ->delete();
            }

            return response()->json(['message' => 'Tu cuenta está desactivada.'], 403);
        }

        return $next($request);
    }
}
