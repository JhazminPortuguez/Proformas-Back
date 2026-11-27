<?php

declare(strict_types=1);

namespace App\Support;

use Throwable;

/**
 * Explica en lenguaje sencillo por qué no salió un correo, sin mostrar la
 * contraseña: el mensaje técnico se limpia antes de ir al log o a la
 * pantalla.
 */
final class ErrorCorreo
{
    public static function explicar(Throwable $error): string
    {
        $texto = mb_strtolower(self::limpiar($error->getMessage()));

        return match (true) {
            str_contains($texto, '535') || str_contains($texto, 'username and password not accepted')
                || str_contains($texto, 'authenticat') => 'El servidor de correo rechazó el usuario o la contraseña. Con Gmail hay que usar una "contraseña de aplicación" (no la contraseña normal de la cuenta) y tener activada la verificación en dos pasos.',
            str_contains($texto, 'connection could not be established') || str_contains($texto, 'connection refused')
                || str_contains($texto, 'timed out') || str_contains($texto, 'getaddrinfo')
                || str_contains($texto, 'unable to connect') => 'No se pudo conectar con el servidor de correo. Revisa MAIL_HOST y MAIL_PORT (Gmail: smtp.gmail.com y 587) y que la red permita la conexión.',
            str_contains($texto, 'ssl') || str_contains($texto, 'tls') || str_contains($texto, 'certificate') => 'Falló la conexión segura con el servidor de correo. Con el puerto 587 usa MAIL_SCHEME=smtp (STARTTLS) y con el 465, MAIL_SCHEME=smtps.',
            str_contains($texto, 'sender') || str_contains($texto, 'from') => 'El servidor no aceptó la dirección del remitente. MAIL_FROM_ADDRESS debe ser la misma cuenta de MAIL_USERNAME (o un alias autorizado).',
            default => 'El servidor de correo no aceptó el envío. El detalle técnico quedó en el log del día, en storage/logs.',
        };
    }

    /** El mensaje técnico sin la contraseña del correo (por si el servidor la repitiera). */
    public static function limpiar(string $mensaje): string
    {
        $password = (string) config('mail.mailers.smtp.password', '');

        if ($password !== '') {
            $mensaje = str_replace([$password, str_replace(' ', '', $password)], '********', $mensaje);
        }

        return $mensaje;
    }
}
