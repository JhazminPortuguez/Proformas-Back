<?php

declare(strict_types=1);

namespace App\Enums;

enum OrigenProforma: string
{
    case Interna = 'INTERNA';
    case Solicitud = 'SOLICITUD';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Interna => 'Interna',
            self::Solicitud => 'Solicitud',
        };
    }
}
