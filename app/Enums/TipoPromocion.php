<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Ronda C, parte 1: cómo descuenta una promoción sobre una línea.
 *
 * - Porcentaje: valor de 1 a 100 sobre el importe de la línea.
 * - Monto fijo: valor en Bs que se resta una vez a la línea (nunca la deja
 *   en negativo).
 * - 2x1: de cada 2 unidades se paga 1 (con 1 unidad no descuenta; con 3 se
 *   cobran 2).
 */
enum TipoPromocion: string
{
    case Porcentaje = 'PORCENTAJE';
    case MontoFijo = 'MONTO_FIJO';
    case DosPorUno = 'DOS_POR_UNO';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Porcentaje => 'Porcentaje',
            self::MontoFijo => 'Monto fijo',
            self::DosPorUno => '2x1',
        };
    }

    public function usaValor(): bool
    {
        return $this !== self::DosPorUno;
    }
}
