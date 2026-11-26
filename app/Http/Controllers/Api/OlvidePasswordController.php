<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\CuentaSoloGoogle;
use App\Notifications\RestablecerContrasena;
use App\Support\ReglaPassword;
use Illuminate\Auth\Passwords\TokenRepositoryInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Ronda C, parte 2: "Olvidé mi contraseña".
 *
 * - solicitar: la respuesta es siempre la misma, exista o no el correo, para
 *   no revelar quién está registrado. Si la cuenta existe y está activa,
 *   recibe el enlace; si se creó con Google, un correo que le dice que entre
 *   con Google. Limitado por correo e IP (throttle `olvide-password`).
 * - restablecer: el enlace vence en 60 minutos y sirve una sola vez. La
 *   contraseña nueva cumple la misma regla que el registro; al cambiarla se
 *   cierran todas las sesiones abiertas de la cuenta.
 */
class OlvidePasswordController extends Controller
{
    public const MENSAJE_SOLICITUD = 'Si el correo está registrado, te enviamos un enlace para elegir una contraseña nueva. Vence en 60 minutos. Revisa también la carpeta de spam.';

    public const MENSAJE_ENLACE_INVALIDO = 'El enlace no es válido, ya se usó o venció. Pide uno nuevo en "¿Olvidaste tu contraseña?".';

    public function solicitar(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
        ], [
            'email.required' => 'Escribe tu correo electrónico.',
            'email.email' => 'Escribe un correo electrónico válido.',
        ]);

        $usuario = User::query()->whereRaw('lower(email) = ?', [Str::lower(trim($datos['email']))])->first();

        if ($usuario !== null && $usuario->activo) {
            try {
                if ($usuario->solo_google) {
                    $usuario->notify(new CuentaSoloGoogle);
                    Log::info('Olvidé mi contraseña: la cuenta entra con Google; se le avisó por correo.', ['user_id' => $usuario->id]);
                } elseif (! $this->tokens()->recentlyCreatedToken($usuario)) {
                    $usuario->notify(new RestablecerContrasena($this->tokens()->create($usuario)));
                    Log::info('Olvidé mi contraseña: se envió el enlace.', ['user_id' => $usuario->id]);
                }
            } catch (Throwable $error) {
                // Un fallo del correo no cambia la respuesta (no revela si la cuenta existe).
                Log::error('Olvidé mi contraseña: no se pudo enviar el correo.', ['user_id' => $usuario->id, 'error' => $error->getMessage()]);
            }
        }

        return response()->json(['message' => self::MENSAJE_SOLICITUD]);
    }

    public function restablecer(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'token' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ReglaPassword::reglas(),
        ], [
            'token.required' => self::MENSAJE_ENLACE_INVALIDO,
            'email.required' => self::MENSAJE_ENLACE_INVALIDO,
            'email.email' => self::MENSAJE_ENLACE_INVALIDO,
            ...ReglaPassword::mensajes('password', 'La contraseña nueva'),
        ]);

        $usuario = User::query()->whereRaw('lower(email) = ?', [Str::lower(trim($datos['email']))])->first();

        if ($usuario === null || ! $usuario->activo || $usuario->solo_google || ! $this->tokens()->exists($usuario, $datos['token'])) {
            Log::warning('Olvidé mi contraseña: enlace inválido, usado o vencido.', ['ip' => $request->ip()]);

            throw ValidationException::withMessages(['token' => self::MENSAJE_ENLACE_INVALIDO]);
        }

        DB::transaction(function () use ($usuario, $datos): void {
            $usuario->forceFill([
                'password' => $datos['password'],
                'remember_token' => null,
                // Quien abrió el enlace del correo demostró que el correo es suyo.
                'email_verified_at' => $usuario->email_verified_at ?? now(),
            ])->save();

            // Sirve una sola vez.
            $this->tokens()->delete($usuario);

            // Se cierran todas las sesiones abiertas con la cuenta.
            if (config('session.driver') === 'database') {
                DB::connection(config('session.connection'))
                    ->table((string) config('session.table', 'sessions'))
                    ->where('user_id', $usuario->id)
                    ->delete();
            }
        });

        Log::info('Olvidé mi contraseña: el usuario eligió una contraseña nueva.', ['user_id' => $usuario->id, 'ip' => $request->ip()]);

        return response()->json(['message' => 'Tu contraseña se cambió. Ya puedes iniciar sesión con la nueva. Las sesiones que tenías abiertas se cerraron.']);
    }

    private function tokens(): TokenRepositoryInterface
    {
        return Password::broker()->getRepository();
    }
}
