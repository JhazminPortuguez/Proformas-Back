<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Empresa;
use App\Models\User;
use App\Support\EmpresaActual;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

/**
 * Ronda A, parte 3: correo con el enlace firmado para verificar el correo del
 * cliente. El enlace va al back, que verifica y lleva de vuelta al front.
 * Vence en `auth.verification.expire` minutos (60).
 */
class VerificarCorreoCliente extends Notification
{
    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Firma relativa y con la base del front: el front reenvía /api al back,
     * así el enlace funciona aunque el back no sea público.
     */
    public static function enlace(User $usuario): string
    {
        $ruta = URL::temporarySignedRoute(
            'verificacion.verificar',
            now()->addMinutes(self::minutos()),
            ['id' => $usuario->getKey(), 'hash' => sha1($usuario->getEmailForVerification())],
            absolute: false,
        );

        return rtrim((string) config('app.frontend_url'), '/').$ruta;
    }

    public static function minutos(): int
    {
        return (int) config('auth.verification.expire', 60);
    }

    public function toMail(User $notifiable): MailMessage
    {
        $empresa = Empresa::query()->find($notifiable->empresa_id ?? EmpresaActual::id());
        $nombreEmpresa = $empresa?->nombre ?? config('app.name');

        return (new MailMessage)
            ->subject("Verifica tu correo en el portal de {$nombreEmpresa}")
            ->view('mail.verificar-correo', [
                'nombre' => $notifiable->name,
                'empresa' => $nombreEmpresa,
                'enlace' => self::enlace($notifiable),
                'minutos' => self::minutos(),
            ]);
    }
}
