<?php

declare(strict_types=1);

namespace App\Actions\Proformas;

use App\Models\Proforma;
use App\Models\User;
use App\Support\Dinero;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ActualizarProforma
{
    private const CAMPOS_CONTENIDO = [
        'cliente_id',
        'fecha_emision',
        'fecha_validez',
        'items',
        'descuento',
        'impuesto_tasa',
        'moneda',
    ];

    public function __construct(
        private readonly ConstruirLineasProforma $lineas,
        private readonly CalcularTotalesProforma $totales,
    ) {}

    /**
     * @param  array<string, mixed>  $datos
     */
    public function ejecutar(Proforma $proforma, array $datos, User $usuario): Proforma
    {
        return DB::transaction(function () use ($proforma, $datos, $usuario) {
            $actual = Proforma::query()
                ->whereKey($proforma->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $editaContenido = collect(self::CAMPOS_CONTENIDO)
                ->contains(fn (string $campo) => array_key_exists($campo, $datos));

            if ($editaContenido && ! $actual->estado->permiteEditarContenido()) {
                throw ValidationException::withMessages([
                    'estado' => 'Solo se puede editar el contenido de una proforma en borrador.',
                ]);
            }

            $cambios = [];

            foreach (['cliente_id', 'fecha_emision', 'fecha_validez', 'observaciones', 'moneda'] as $campo) {
                if (array_key_exists($campo, $datos)) {
                    $cambios[$campo] = $campo === 'moneda'
                        ? strtoupper((string) $datos[$campo])
                        : $datos[$campo];
                }
            }

            $fechaEmision = $datos['fecha_emision'] ?? $actual->fecha_emision?->toDateString();
            $fechaValidez = array_key_exists('fecha_validez', $datos)
                ? $datos['fecha_validez']
                : $actual->fecha_validez?->toDateString();

            if ($fechaValidez !== null && $fechaEmision !== null && $fechaValidez < $fechaEmision) {
                throw ValidationException::withMessages([
                    'fecha_validez' => 'La fecha de validez no puede ser anterior a la fecha de emisión.',
                ]);
            }

            if (array_key_exists('items', $datos)) {
                $calculado = $this->lineas->ejecutar($datos['items']);
                $actual->items()->delete();
                $actual->items()->createMany($calculado['lineas']);
                $subtotal = $calculado['subtotal'];
            } else {
                $subtotal = Dinero::redondear((string) $actual->items()->sum('total_linea'));
            }

            $totales = $this->totales->ejecutar(
                $subtotal,
                array_key_exists('descuento', $datos) ? $datos['descuento'] : $actual->descuento,
                array_key_exists('impuesto_tasa', $datos) ? $datos['impuesto_tasa'] : $actual->impuesto_tasa,
            );

            $actual->update([
                ...$cambios,
                'subtotal' => $totales['subtotal'],
                'descuento' => $totales['descuento'],
                'impuesto_tasa' => $totales['impuesto_tasa'],
                'impuesto' => $totales['impuesto'],
                'total' => $totales['total'],
                'updated_by' => $usuario->id,
            ]);

            return $actual->load('cliente', 'items.producto', 'items.servicio');
        });
    }
}
