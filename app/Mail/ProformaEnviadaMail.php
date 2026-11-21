<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Empresa;
use App\Models\Proforma;
use App\Support\TextoProforma;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Correo al cliente con la proforma: número, total, validez, enlace al
 * portal y el mismo PDF que se descarga en el sistema.
 */
class ProformaEnviadaMail extends Mailable
{
    public function __construct(
        public readonly Proforma $proforma,
        public readonly Empresa $empresa,
        private readonly string $pdf,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Proforma {$this->proforma->numero} de {$this->empresa->nombre}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.proforma-enviada',
            with: [
                'proforma' => $this->proforma,
                'empresa' => $this->empresa,
                'cliente' => $this->proforma->cliente,
                'total' => TextoProforma::total($this->proforma),
                'validez' => TextoProforma::validez($this->proforma),
                'enlace' => TextoProforma::enlacePortal($this->proforma),
            ],
        );
    }

    /**
     * @return list<Attachment>
     */
    public function attachments(): array
    {
        return [
            Attachment::fromData(fn () => $this->pdf, "{$this->proforma->numero}.pdf")
                ->withMime('application/pdf'),
        ];
    }
}
