<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\EstadoCliente;
use App\Enums\Rol;
use App\Http\Controllers\Controller;
use App\Models\Cliente;
use App\Models\User;
use App\Support\EmpresaActual;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Contracts\User as UsuarioGoogle;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

/**
 * Ronda B, parte 2: "Continuar con Google" (Laravel Socialite).
 *
 * - Correo que ya existe: entra con ese usuario si está activo. El
 *   superusuario no entra con Google (solo con contraseña).
 * - Correo nuevo: se crea un cliente Prospecto con el nombre de Google y su
 *   usuario cliente; antes de enviar solicitudes completa teléfono y NIT/CI.
 * - Correo de un cliente que aún no tiene usuario: igual que en el registro,
 *   no se vincula solo (pide a la empresa su acceso).
 * - Quien entra con Google queda con el correo verificado (Google ya lo
 *   verificó; si Google dice que no, no entra).
 *
 * El resultado vuelve al front: el inicio del rol o /login?google=<motivo>.
 */
class GoogleController extends Controller
{
    /** Motivos que muestra /login (el front tiene el texto de cada uno). */
    public const SUPERUSUARIO = 'superusuario';

    public const INACTIVO = 'inactivo';

    public const SIN_VERIFICAR = 'sin-verificar';

    public const CANCELADO = 'cancelado';

    public const CLIENTE_EXISTENTE = 'cliente-existente';

    public const ERROR = 'error';

    public static function habilitado(): bool
    {
        return filled(config('services.google.client_id')) && filled(config('services.google.client_secret'));
    }

    public function estado(): JsonResponse
    {
        return response()->json(['habilitado' => self::habilitado()]);
    }

    public function redirect(): RedirectResponse
    {
        abort_unless(self::habilitado(), 404, 'El inicio de sesión con Google no está configurado.');

        return Socialite::driver('google')
            ->scopes(['openid', 'email', 'profile'])
            ->with(['prompt' => 'select_account'])
            ->redirect();
    }

    public function callback(Request $request): RedirectResponse
    {
        abort_unless(self::habilitado(), 404, 'El inicio de sesión con Google no está configurado.');

        // La persona canceló en la pantalla de Google.
        if ($request->filled('error')) {
            return $this->alLogin(self::CANCELADO);
        }

        try {
            $google = Socialite::driver('google')->user();
        } catch (Throwable $error) {
            // State inválido o vencido, código usado dos veces, Google caído…
            Log::warning('Falló el inicio de sesión con Google.', ['error' => $error->getMessage()]);

            return $this->alLogin(self::ERROR);
        }

        $email = Str::lower(trim((string) $google->getEmail()));

        if ($email === '' || ! $this->correoVerificadoPorGoogle($google)) {
            return $this->alLogin(self::SIN_VERIFICAR);
        }

        $usuario = User::query()->withoutGlobalScope('empresa')->where('email', $email)->first();

        if ($usuario !== null) {
            if ($usuario->rol === Rol::Superusuario) {
                return $this->alLogin(self::SUPERUSUARIO);
            }

            if (! $usuario->activo) {
                return $this->alLogin(self::INACTIVO);
            }

            $usuario->forceFill([
                'google_id' => $usuario->google_id ?? (string) $google->getId(),
                'email_verified_at' => $usuario->email_verified_at ?? now(),
            ])->save();
        } elseif (Cliente::query()->whereRaw('lower(email) = ?', [$email])->exists()) {
            return $this->alLogin(self::CLIENTE_EXISTENTE);
        } else {
            $usuario = $this->crearCliente($google, $email);
        }

        Auth::login($usuario);
        $request->session()->regenerate();
        $request->session()->put('empresa_id', $usuario->empresa_id);

        return redirect()->away($this->front($usuario->rol === Rol::Cliente ? '/portal' : '/dashboard'));
    }

    private function crearCliente(UsuarioGoogle $google, string $email): User
    {
        $empresaId = EmpresaActual::id();
        $nombre = Str::limit(trim((string) ($google->getName() ?: Str::before($email, '@'))), 200, '');

        return DB::transaction(function () use ($google, $email, $empresaId, $nombre): User {
            $cliente = Cliente::create([
                'nombre_razon' => $nombre,
                'email' => $email,
                'estado' => EstadoCliente::Prospecto,
                'empresa_id' => $empresaId,
            ]);

            $usuario = new User([
                'name' => $nombre,
                'email' => $email,
                // No tiene contraseña propia: una aleatoria que nadie conoce.
                'password' => Str::random(40),
                'activo' => true,
                'cliente_id' => $cliente->id,
                'empresa_id' => $empresaId,
            ]);
            $usuario->rol = Rol::Cliente;
            $usuario->google_id = (string) $google->getId();
            $usuario->email_verified_at = now();
            $usuario->datos_pendientes = true;
            $usuario->save();

            return $usuario;
        });
    }

    /** Google manda `email_verified` (o `verified_email` en la API v2). */
    private function correoVerificadoPorGoogle(UsuarioGoogle $google): bool
    {
        $crudo = method_exists($google, 'getRaw') ? (array) $google->getRaw() : [];
        $valor = $crudo['email_verified'] ?? $crudo['verified_email'] ?? false;

        return $valor === true || $valor === 'true' || $valor === 1 || $valor === '1';
    }

    private function alLogin(string $motivo): RedirectResponse
    {
        return redirect()->away($this->front('/login?google='.$motivo));
    }

    private function front(string $ruta): string
    {
        return rtrim((string) config('app.frontend_url'), '/').$ruta;
    }
}
