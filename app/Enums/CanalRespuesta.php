<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Por dónde llegó la respuesta del cliente a una proforma: él mismo desde el
 * portal, o el personal que la registró (por ejemplo, tras una llamada).
 */
enum CanalRespuesta: string
{
    case Portal = 'PORTAL';
    case Interno = 'INTERNO';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Portal => 'Portal del cliente',
            self::Interno => 'Registrada por el personal',
        };
    }
}
