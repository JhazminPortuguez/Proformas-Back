<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\TipoPromocion;

/**
 * Ronda C, parte 1: descuento de una promoción sobre una línea (en Bs, con
 * bcmath). Nunca supera el importe de la línea: la línea queda en Bs 0 como
 * mínimo, nunca en negativo.
 */
final class DescuentoPromocion
{
    public static function calcular(
        TipoPromocion $tipo,
        float|int|string|null $valor,
        float|int|string $cantidad,
        float|int|string $precioUnitario,
    ): string {
        $bruto = Dinero::totalLinea($cantidad, $precioUnitario);

        $descuento = match ($tipo) {
            TipoPromocion::Porcentaje => Dinero::porcentaje($bruto, $valor ?? 0),
            TipoPromocion::MontoFijo => Dinero::redondear($valor ?? 0),
            // De cada 2 unidades se paga 1: las unidades gratis son la parte
            // entera de cantidad / 2 (1 → 0, 2 → 1, 3 → 1, 4 → 2).
            TipoPromocion::DosPorUno => Dinero::totalLinea(
                bcdiv(Dinero::cantidad($cantidad), '2', 0),
                $precioUnitario,
            ),
        };

        return Dinero::mayorQue($descuento, $bruto) ? $bruto : $descuento;
    }

    /** "10 %", "Bs 50,00" o "2x1", para el PDF, el correo y la pantalla. */
    public static function etiqueta(TipoPromocion|string|null $tipo, float|int|string|null $valor): string
    {
        $tipo = $tipo instanceof TipoPromocion ? $tipo : TipoPromocion::tryFrom((string) $tipo);

        return match ($tipo) {
            TipoPromocion::Porcentaje => rtrim(rtrim(number_format((float) $valor, 2, ',', '.'), '0'), ',').' %',
            TipoPromocion::MontoFijo => 'Bs '.number_format((float) $valor, 2, ',', '.'),
            TipoPromocion::DosPorUno => '2x1',
            null => '',
        };
    }
}
