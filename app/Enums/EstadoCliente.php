<?php

declare(strict_types=1);

namespace App\Enums;

enum EstadoCliente: string
{
    case Prospecto = 'prospecto';
    case Activo = 'activo';
    case Inactivo = 'inactivo';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Prospecto => 'Prospecto',
            self::Activo => 'Activo',
            self::Inactivo => 'Inactivo',
        };
    }
}
