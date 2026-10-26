<?php

declare(strict_types=1);

namespace App\Enums;

enum EstadoCobro: string
{
    case Pendiente = 'PENDIENTE';
    case Parcial = 'PARCIAL';
    case Pagada = 'PAGADA';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Pendiente => 'Pendiente',
            self::Parcial => 'Parcial',
            self::Pagada => 'Pagada',
        };
    }

    public function esFinal(): bool
    {
        return $this === self::Pagada;
    }
}
