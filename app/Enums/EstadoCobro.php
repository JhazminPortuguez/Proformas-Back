<?php

declare(strict_types=1);

namespace App\Enums;

enum EstadoCobro: string
{
    case Pendiente = 'PENDIENTE';
    case Parcial = 'PARCIAL';
    case Pagada = 'PAGADA';

    /** X-11: anulada por el admin, sin pagos vigentes; no se cobra. */
    case Anulada = 'ANULADA';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Pendiente => 'Pendiente',
            self::Parcial => 'Parcial',
            self::Pagada => 'Pagada',
            self::Anulada => 'Anulada',
        };
    }

    public function esFinal(): bool
    {
        return $this === self::Pagada || $this === self::Anulada;
    }
}
