<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Proforma;

/**
 * Datos de una proforma tal como se leen en un correo o un mensaje: el total
 * con el formato de la pantalla ("Bs 1.250,75") y la validez dd/mm/aaaa.
 */
final class TextoProforma
{
    public static function total(Proforma $proforma): string
    {
        $moneda = $proforma->moneda ?: Monedas::POR_DEFECTO;

        return Monedas::simbolo($moneda).' '.number_format((float) $proforma->total, 2, ',', '.');
    }

    public static function validez(Proforma $proforma): ?string
    {
        return $proforma->fecha_validez !== null ? FechaBolivia::formato($proforma->fecha_validez) : null;
    }

    /** Enlace a la proforma en el portal del cliente (front). */
    public static function enlacePortal(Proforma $proforma): string
    {
        return rtrim((string) config('app.frontend_url'), '/')."/portal/proformas/{$proforma->id}";
    }
}
