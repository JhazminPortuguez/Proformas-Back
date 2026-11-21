<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\Rol;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\VerificarCorreoCliente;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Throwable;

/**
 * Ronda A, parte 3: verificación del correo del cliente (MustVerifyEmail).
 *
 * El enlace del correo apunta aquí (firmado, vence en 60 minutos). No hace
 * falta sesión: la firma prueba que el enlace salió del sistema. Siempre
 * vuelve al front, a /verificar-correo?estado=…, que explica el resultado.
 */
class VerificacionCorreoController extends Controller
{
    public function verificar(Request $request, string $id, string $hash): RedirectResponse
    {
        if (! URL::hasCorrectSignature($request, absolute: false)) {
            return $this->alFront('invalido');
        }

        if (! URL::signatureHasNotExpired($request)) {
            return $this->alFront('vencido');
        }

        // Sin el filtro de empresa: el enlace llega sin sesión.
        $usuario = User::query()->withoutGlobalScopes()->find($id);

        if ($usuario === null || ! hash_equals(sha1($usuario->getEmailForVerification()), $hash)) {
            return $this->alFront('invalido');
        }

        if ($usuario->hasVerifiedEmail()) {
            return $this->alFront('ya-verificado');
        }

        $usuario->markEmailAsVerified();
        event(new Verified($usuario));

        return $this->alFront('verificado');
    }

    /** "Reenviar enlace": con límite de envíos (throttle:reenvio-verificacion). */
    public function reenviar(Request $request): JsonResponse
    {
        /** @var User $usuario */
        $usuario = $request->user();

        if ($usuario->rol !== Rol::Cliente || $usuario->hasVerifiedEmail()) {
            return response()->json(['message' => 'Tu correo ya está verificado.', 'enviado' => false]);
        }

        try {
            $usuario->sendEmailVerificationNotification();
        } catch (Throwable $error) {
            Log::error('No se pudo reenviar el enlace de verificación.', [
                'user_id' => $usuario->id,
                'error' => $error->getMessage(),
            ]);

            return response()->json([
                'message' => 'No pudimos enviar el correo ahora. Inténtalo de nuevo en unos minutos.',
                'enviado' => false,
            ], 503);
        }

        $minutos = VerificarCorreoCliente::minutos();

        return response()->json([
            'message' => "Te enviamos un enlace nuevo a {$usuario->email}. Vence en {$minutos} minutos.",
            'enviado' => true,
        ]);
    }

    private function alFront(string $estado): RedirectResponse
    {
        return redirect()->away(rtrim((string) config('app.frontend_url'), '/')."/verificar-correo?estado={$estado}");
    }
}
