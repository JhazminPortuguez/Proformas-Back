<?php

declare(strict_types=1);

namespace App\Actions\Proformas;

use App\Enums\EstadoProforma;
use App\Enums\OrigenProforma;
use App\Models\Proforma;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CrearProforma
{
    public function __construct(
        private readonly GenerarNumeroProforma $numeros,
        private readonly ConstruirLineasProforma $lineas,
        private readonly CalcularTotalesProforma $totales,
    ) {}

    /**
     * @param  array<string, mixed>  $datos
     */
    public function ejecutar(array $datos, User $usuario): Proforma
    {
        return DB::transaction(function () use ($datos, $usuario) {
            $calculado = $this->lineas->ejecutar($datos['items']);
            $totales = $this->totales->ejecutar(
                $calculado['subtotal'],
                $datos['descuento'] ?? 0,
                $datos['impuesto_tasa'] ?? 0,
            );

            $fechaEmision = CarbonImmutable::parse($datos['fecha_emision']);
            $fechaValidez = $datos['fecha_validez'] ?? null;

            if ($fechaValidez !== null && CarbonImmutable::parse($fechaValidez)->lt($fechaEmision)) {
                throw ValidationException::withMessages([
                    'fecha_validez' => 'La fecha de validez no puede ser anterior a la fecha de emisión.',
                ]);
            }

            $proforma = Proforma::create([
                'numero' => $this->numeros->ejecutar($fechaEmision),
                'cliente_id' => $datos['cliente_id'],
                'fecha_emision' => $fechaEmision->toDateString(),
                'fecha_validez' => $fechaValidez,
                'estado' => EstadoProforma::Borrador,
                'moneda' => strtoupper((string) ($datos['moneda'] ?? 'BOB')),
                'impuesto_tasa' => $totales['impuesto_tasa'],
                'subtotal' => $totales['subtotal'],
                'descuento' => $totales['descuento'],
                'impuesto' => $totales['impuesto'],
                'total' => $totales['total'],
                'observaciones' => $datos['observaciones'] ?? null,
                'origen' => $datos['origen'] ?? OrigenProforma::Interna->value,
                'solicitud_id' => $datos['solicitud_id'] ?? null,
                'created_by' => $usuario->id,
                'updated_by' => $usuario->id,
            ]);

            $proforma->items()->createMany($calculado['lineas']);

            return $proforma->load('cliente', 'items.producto', 'items.servicio');
        });
    }
}
