<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Empresa;
use App\Models\OrdenServicio;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Aviso al cliente de que su orden de servicio terminó (opcional de la parte 2). */
class OrdenFinalizadaMail extends Mailable
{
    public function __construct(
        public readonly OrdenServicio $orden,
        public readonly Empresa $empresa,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Tu servicio {$this->orden->numero} de {$this->empresa->nombre} está terminado",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.orden-finalizada',
            with: [
                'orden' => $this->orden,
                'empresa' => $this->empresa,
                'cliente' => $this->orden->cliente,
                'enlace' => rtrim((string) config('app.frontend_url'), '/').'/portal',
            ],
        );
    }
}
