<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Mantiene el contrato plano de la API (sin envoltorio "data").
        JsonResource::withoutWrapping();

        RateLimiter::for('login', function (Request $request) {
            $email = Str::lower((string) $request->input('email'));

            return [
                Limit::perMinute(5)->by($email.'|'.$request->ip()),
                Limit::perMinute(20)->by($request->ip()),
            ];
        });

        RateLimiter::for('register', function (Request $request) {
            return Limit::perMinute(5)->by($request->ip());
        });

        // Portal: un cliente no puede enviar solicitudes en ráfaga.
        RateLimiter::for('portal-solicitudes', function (Request $request) {
            return Limit::perMinute(10)->by((string) ($request->user()?->getKey() ?? $request->ip()));
        });

        // Reenviar una proforma por correo: evita mandar el mismo correo en ráfaga.
        RateLimiter::for('reenvio-correo', function (Request $request) {
            return Limit::perMinute(5)->by('reenvio|'.($request->user()?->getKey() ?? $request->ip()));
        });

        // "Reenviar enlace" de verificación: 3 cada 10 minutos por usuario.
        RateLimiter::for('reenvio-verificacion', function (Request $request) {
            return Limit::perMinutes(10, 3)
                ->by('verificacion|'.($request->user()?->getKey() ?? $request->ip()))
                ->response(fn (Request $request, array $headers) => response()->json([
                    'message' => 'Ya pediste varios enlaces seguidos. Espera unos minutos y revisa tu bandeja (también la carpeta de spam) antes de pedir otro.',
                ], 429, $headers));
        });

        // Ronda B, parte 3: cambiar la propia contraseña (adivinar la actual).
        RateLimiter::for('cambiar-password', function (Request $request) {
            return Limit::perMinute(5)->by('password|'.($request->user()?->getKey() ?? $request->ip()));
        });

        // Ronda C: "Olvidé mi contraseña". 3 pedidos cada 10 minutos por
        // correo e IP y 10 por hora por IP; el mensaje no dice si existe.
        RateLimiter::for('olvide-password', function (Request $request) {
            $email = Str::lower(trim((string) $request->input('email')));
            $respuesta = fn (Request $request, array $headers) => response()->json([
                'message' => 'Hiciste varios pedidos seguidos. Espera unos minutos y revisa tu bandeja (también la carpeta de spam) antes de pedir otro enlace.',
            ], 429, $headers);

            return [
                Limit::perMinutes(10, 3)->by('olvide|'.$email.'|'.$request->ip())->response($respuesta),
                Limit::perHour(10)->by('olvide-ip|'.$request->ip())->response($respuesta),
            ];
        });

        RateLimiter::for('restablecer-password', function (Request $request) {
            return Limit::perMinute(5)->by('restablecer|'.$request->ip());
        });

        // Ronda B: "Enviar correo de prueba" del superusuario.
        RateLimiter::for('correo-prueba', function (Request $request) {
            return Limit::perMinute(3)
                ->by('correo-prueba|'.($request->user()?->getKey() ?? $request->ip()))
                ->response(fn (Request $request, array $headers) => response()->json([
                    'message' => 'Ya enviaste varios correos de prueba seguidos. Espera un minuto antes de enviar otro.',
                ], 429, $headers));
        });

        // Portal: aceptar o rechazar proformas (P-11 / U-05).
        RateLimiter::for('portal-respuestas', function (Request $request) {
            return Limit::perMinute(10)->by('respuestas|'.($request->user()?->getKey() ?? $request->ip()));
        });
    }
}
