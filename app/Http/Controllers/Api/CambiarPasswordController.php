<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rules\Password;
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
            'password' => ['required', 'string', 'max:255', 'confirmed', Password::min(8)->letters()->numbers(), 'different:password_actual'],
        ], [
            'password_actual.required' => 'Escribe tu contraseña actual.',
            'password.required' => 'Escribe la contraseña nueva.',
            'password.confirmed' => 'La confirmación no coincide con la contraseña nueva.',
            'password.different' => 'La contraseña nueva debe ser distinta de la actual.',
            'password.min' => 'La contraseña nueva debe tener al menos 8 caracteres.',
            'password.letters' => 'La contraseña nueva debe tener al menos una letra.',
            'password.numbers' => 'La contraseña nueva debe tener al menos un número.',
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
