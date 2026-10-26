<?php

declare(strict_types=1);

namespace App\Enums;

enum TipoOrdenEvento: string
{
    case Creacion = 'CREACION';
    case Asignacion = 'ASIGNACION';
    case Inicio = 'INICIO';
    case Finalizacion = 'FINALIZACION';
    case Cancelacion = 'CANCELACION';
    case Cierre = 'CIERRE';
    case Consumo = 'CONSUMO';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Creacion => 'Creación',
            self::Asignacion => 'Asignación',
            self::Inicio => 'Inicio',
            self::Finalizacion => 'Finalización',
            self::Cancelacion => 'Cancelación',
            self::Cierre => 'Cierre',
            self::Consumo => 'Consumo de materiales',
        };
    }
}
