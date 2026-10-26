<?php

declare(strict_types=1);

namespace App\Actions\Proformas;

use App\Support\Dinero;
use Illuminate\Validation\ValidationException;

final class CalcularTotalesProforma
{
    /**
     * @return array{subtotal: string, descuento: string, impuesto_tasa: string, impuesto: string, total: string}
     */
    public function ejecutar(
        float|int|string $subtotal,
        float|int|string|null $descuento,
        float|int|string|null $impuestoTasa,
    ): array {
        $subtotal = Dinero::redondear($subtotal);
        $descuento = Dinero::redondear($descuento ?? 0);
        $tasa = Dinero::redondear($impuestoTasa ?? 0);

        if (Dinero::mayorQue($subtotal, Dinero::MONTO_MAXIMO)) {
            throw ValidationException::withMessages([
                'items' => 'El subtotal de la proforma excede el máximo permitido.',
            ]);
        }

        if (Dinero::mayorQue($descuento, Dinero::MONTO_MAXIMO)) {
            throw ValidationException::withMessages([
                'descuento' => 'El descuento excede el máximo permitido.',
            ]);
        }

        if (Dinero::comparar($descuento, 0) < 0) {
            throw ValidationException::withMessages([
                'descuento' => 'El descuento no puede ser negativo.',
            ]);
        }

        if (Dinero::mayorQue($descuento, $subtotal)) {
            throw ValidationException::withMessages([
                'descuento' => 'El descuento no puede superar el subtotal.',
            ]);
        }

        if (Dinero::comparar($tasa, 0) < 0 || Dinero::comparar($tasa, 100) > 0) {
            throw ValidationException::withMessages([
                'impuesto_tasa' => 'La tasa de impuesto debe estar entre 0 y 100.',
            ]);
        }

        $base = Dinero::restar($subtotal, $descuento);
        $impuesto = Dinero::porcentaje($base, $tasa);
        $total = Dinero::sumar($base, $impuesto);

        if (Dinero::mayorQue($total, Dinero::MONTO_MAXIMO)) {
            throw ValidationException::withMessages([
                'items' => 'El total de la proforma excede el máximo permitido.',
            ]);
        }

        return [
            'subtotal' => $subtotal,
            'descuento' => $descuento,
            'impuesto_tasa' => $tasa,
            'impuesto' => $impuesto,
            'total' => $total,
        ];
    }
}
