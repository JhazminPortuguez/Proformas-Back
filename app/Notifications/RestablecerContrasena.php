<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Empresa;
use App\Models\User;
use App\Support\EmpresaActual;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Ronda C, parte 2: enlace para elegir una contraseña nueva. Lleva al front
 * (/restablecer-contrasena), vence en `auth.passwords.users.expire` minutos
 * (60) y sirve una sola vez.
 */
class RestablecerContrasena extends Notification
{
    public function __construct(private readonly string $token) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public static function enlace(User $usuario, string $token): string
    {
        return rtrim((string) config('app.frontend_url'), '/').'/restablecer-contrasena?'.http_build_query([
            'token' => $token,
            'email' => $usuario->email,
        ]);
    }

    public static function minutos(): int
    {
        return (int) config('auth.passwords.users.expire', 60);
    }

    public function toMail(User $notifiable): MailMessage
    {
        $empresa = Empresa::query()->find($notifiable->empresa_id ?? EmpresaActual::id());
        $nombreEmpresa = $empresa?->nombre ?? config('app.name');

        return (new MailMessage)
            ->subject("Elige una contraseña nueva para {$nombreEmpresa}")
            ->view('mail.restablecer-contrasena', [
                'nombre' => $notifiable->name,
                'empresa' => $nombreEmpresa,
                'enlace' => self::enlace($notifiable, $this->token),
                'minutos' => self::minutos(),
            ]);
    }
}
