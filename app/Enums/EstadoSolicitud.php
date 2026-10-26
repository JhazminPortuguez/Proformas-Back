<?php

declare(strict_types=1);

namespace App\Enums;

enum EstadoSolicitud: string
{
    case Pendiente = 'PENDIENTE';
    case Atendida = 'ATENDIDA';
    case Rechazada = 'RECHAZADA';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Pendiente => 'Pendiente',
            self::Atendida => 'Atendida',
            self::Rechazada => 'Rechazada',
        };
    }

    public function esFinal(): bool
    {
        return $this !== self::Pendiente;
    }

    public function permiteEditar(): bool
    {
        return $this === self::Pendiente;
    }
}
