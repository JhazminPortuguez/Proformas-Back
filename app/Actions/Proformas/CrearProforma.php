<?php

declare(strict_types=1);

namespace App\Actions\Proformas;

use App\Enums\EstadoProforma;
use App\Models\Proforma;
use App\Models\User;
use App\Support\Dinero;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CrearProforma
{
    public function __construct(
        private readonly GenerarNumeroProforma $numeros,
        private readonly ConstruirLineasProforma $lineas,
    ) {}

    /**
     * @param  array<string, mixed>  $datos
     */
    public function ejecutar(array $datos, User $usuario): Proforma
    {
        return DB::transaction(function () use ($datos, $usuario) {
            $calculado = $this->lineas->ejecutar($datos['items']);
            $subtotal = $calculado['subtotal'];

            $descuento = Dinero::redondear($datos['descuento'] ?? 0);

            if ($descuento > $subtotal) {
                throw ValidationException::withMessages([
                    'descuento' => 'El descuento no puede superar el subtotal.',
                ]);
            }

            $impuesto = Dinero::redondear($datos['impuesto'] ?? 0);
            $total = Dinero::redondear($subtotal - $descuento + $impuesto);

            $fechaEmision = CarbonImmutable::parse($datos['fecha_emision']);

            $proforma = Proforma::create([
                'numero' => $this->numeros->ejecutar($fechaEmision),
                'cliente_id' => $datos['cliente_id'],
                'fecha_emision' => $fechaEmision->toDateString(),
                'fecha_validez' => $datos['fecha_validez'] ?? null,
                'estado' => EstadoProforma::Borrador,
                'subtotal' => $subtotal,
                'descuento' => $descuento,
                'impuesto' => $impuesto,
                'total' => $total,
                'observaciones' => $datos['observaciones'] ?? null,
                'created_by' => $usuario->id,
                'updated_by' => $usuario->id,
            ]);

            $proforma->items()->createMany($calculado['lineas']);

            return $proforma->load('cliente', 'items.producto');
        });
    }
}
