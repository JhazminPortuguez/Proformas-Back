<?php

declare(strict_types=1);

namespace App\Actions\Proformas;

use App\Enums\EstadoProforma;
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
        'impuesto',
    ];

    public function __construct(
        private readonly ConstruirLineasProforma $lineas,
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

            $estadoSolicitado = array_key_exists('estado', $datos)
                ? EstadoProforma::from((string) $datos['estado'])
                : $actual->estado;

            if (! $actual->estado->puedeTransicionarA($estadoSolicitado)) {
                throw ValidationException::withMessages([
                    'estado' => "No se puede pasar de {$actual->estado->etiqueta()} a {$estadoSolicitado->etiqueta()}.",
                ]);
            }

            $editaContenido = collect(self::CAMPOS_CONTENIDO)
                ->contains(fn (string $campo) => array_key_exists($campo, $datos));

            if ($editaContenido && ! $actual->estado->permiteEditarContenido()) {
                throw ValidationException::withMessages([
                    'estado' => 'Solo se puede editar el contenido de una proforma en borrador.',
                ]);
            }

            $cambios = [];

            foreach (['cliente_id', 'fecha_emision', 'fecha_validez', 'observaciones'] as $campo) {
                if (array_key_exists($campo, $datos)) {
                    $cambios[$campo] = $datos[$campo];
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
                $subtotal = Dinero::redondear((float) $actual->items()->sum('total_linea'));
            }

            $descuento = Dinero::redondear(
                array_key_exists('descuento', $datos) ? $datos['descuento'] : $actual->descuento
            );

            if ($descuento > $subtotal) {
                throw ValidationException::withMessages([
                    'descuento' => 'El descuento no puede superar el subtotal.',
                ]);
            }

            $impuesto = Dinero::redondear(
                array_key_exists('impuesto', $datos) ? $datos['impuesto'] : $actual->impuesto
            );

            $actual->update([
                ...$cambios,
                'subtotal' => $subtotal,
                'descuento' => $descuento,
                'impuesto' => $impuesto,
                'total' => Dinero::redondear($subtotal - $descuento + $impuesto),
                'estado' => $estadoSolicitado,
                'updated_by' => $usuario->id,
            ]);

            return $actual->load('cliente', 'items.producto');
        });
    }
}
