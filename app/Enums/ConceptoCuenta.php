<?php

declare(strict_types=1);

namespace App\Enums;

/** Por qué se le cobra al cliente. */
enum ConceptoCuenta: string
{
    /** Orden cerrada: el total de la proforma aceptada. */
    case Servicio = 'SERVICIO';

    /** Orden cancelada: los materiales usados que no se devolvieron al stock. */
    case Cancelacion = 'CANCELACION';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Servicio => 'Servicio',
            self::Cancelacion => 'Cancelación',
        };
    }
}
