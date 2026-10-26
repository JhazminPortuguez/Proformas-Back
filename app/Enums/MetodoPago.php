<?php

declare(strict_types=1);

namespace App\Enums;

enum MetodoPago: string
{
    case Efectivo = 'EFECTIVO';
    case Transferencia = 'TRANSFERENCIA';
    case Qr = 'QR';
    case Tarjeta = 'TARJETA';
    case Otro = 'OTRO';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Efectivo => 'Efectivo',
            self::Transferencia => 'Transferencia',
            self::Qr => 'QR',
            self::Tarjeta => 'Tarjeta',
            self::Otro => 'Otro',
        };
    }
}
