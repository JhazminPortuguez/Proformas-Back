<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Empresa;
use App\Models\User;
use App\Support\EmpresaActual;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Ronda C, parte 2: la cuenta se creó con "Continuar con Google" y no tiene
 * contraseña propia. En vez de un enlace, el correo le dice que entre con
 * Google.
 */
class CuentaSoloGoogle extends Notification
{
    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $empresa = Empresa::query()->find($notifiable->empresa_id ?? EmpresaActual::id());
        $nombreEmpresa = $empresa?->nombre ?? config('app.name');

        return (new MailMessage)
            ->subject("Tu cuenta de {$nombreEmpresa} entra con Google")
            ->view('mail.cuenta-solo-google', [
                'nombre' => $notifiable->name,
                'empresa' => $nombreEmpresa,
                'enlace' => rtrim((string) config('app.frontend_url'), '/').'/login',
            ]);
    }
}
