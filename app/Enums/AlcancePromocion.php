<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Ronda C, parte 1: a qué líneas se puede aplicar una promoción: a un
 * servicio del catálogo, a un producto (material) o a cualquier ítem.
 */
enum AlcancePromocion: string
{
    case Servicio = 'SERVICIO';
    case Producto = 'PRODUCTO';
    case Cualquiera = 'CUALQUIERA';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Servicio => 'Un servicio',
            self::Producto => 'Un producto',
            self::Cualquiera => 'Cualquier ítem',
        };
    }
}
