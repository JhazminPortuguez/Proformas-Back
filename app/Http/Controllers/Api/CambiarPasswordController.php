<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\ReglaPassword;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Ronda B, parte 3 (OWASP A07): cada usuario cambia su propia contraseña
 * (por ejemplo, la temporal del superusuario). Pide la actual, cierra las
 * otras sesiones abiertas de esa cuenta y renueva la sesión actual.
 */
class CambiarPasswordController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'password_actual' => ['required', 'string'],
            'password' => [...ReglaPassword::reglas(), 'different:password_actual'],
        ], [
            'password_actual.required' => 'Escribe tu contraseña actual.',
            ...ReglaPassword::mensajes('password', 'La contraseña nueva'),
            'password.different' => 'La contraseña nueva debe ser distinta de la actual.',
        ]);

        $usuario = $request->user();

        if (! Hash::check($datos['password_actual'], $usuario->password)) {
            Log::warning('Cambio de contraseña con la contraseña actual incorrecta.', ['user_id' => $usuario->id, 'ip' => $request->ip()]);

            throw ValidationException::withMessages(['password_actual' => 'La contraseña actual no es correcta.']);
        }

        $usuario->forceFill([
            'password' => $datos['password'],
            'remember_token' => null,
        ])->save();

        // Las demás sesiones de esta cuenta (otro navegador, otro equipo) se cierran.
        if (config('session.driver') === 'database') {
            DB::connection(config('session.connection'))
                ->table((string) config('session.table', 'sessions'))
                ->where('user_id', $usuario->id)
                ->where('id', '!=', $request->session()->getId())
                ->delete();
        }

        $request->session()->regenerate();

        Log::info('El usuario cambió su contraseña.', ['user_id' => $usuario->id, 'ip' => $request->ip()]);

        return response()->json(['message' => 'Tu contraseña se cambió. Las otras sesiones abiertas con tu cuenta se cerraron.']);
    }
}
