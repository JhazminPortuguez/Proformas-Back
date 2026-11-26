<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\OrdenItem;
use App\Models\ProformaItem;

/**
 * Ronda C: lo que la API muestra de la promoción de una línea (proforma u
 * orden). Sale de la copia guardada en la línea, no de la promoción actual.
 */
final class LineaPromocion
{
    /**
     * @return array{promocion_id: int|null, promocion: array{nombre: string, tipo: string, valor: float|null, etiqueta: string}|null, descuento_promocion: float, importe_bruto: float}
     */
    public static function campos(ProformaItem|OrdenItem $linea): array
    {
        $descuento = Dinero::redondear($linea->descuento_promocion ?? 0);

        return [
            'promocion_id' => $linea->promocion_id,
            'promocion' => $linea->promocion_tipo === null ? null : [
                'nombre' => (string) $linea->promocion_nombre,
                'tipo' => (string) $linea->promocion_tipo,
                'valor' => $linea->promocion_valor === null ? null : (float) $linea->promocion_valor,
                'etiqueta' => DescuentoPromocion::etiqueta($linea->promocion_tipo, $linea->promocion_valor),
            ],
            'descuento_promocion' => (float) $descuento,
            'importe_bruto' => (float) Dinero::sumar($linea->total_linea, $descuento),
        ];
    }
}
