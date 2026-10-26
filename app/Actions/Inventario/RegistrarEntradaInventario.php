<?php

declare(strict_types=1);

namespace App\Actions\Inventario;

use App\Enums\TipoMovimientoInventario;
use App\Models\MovimientoInventario;
use App\Models\Producto;
use App\Models\User;
use App\Support\Dinero;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RegistrarEntradaInventario
{
    /**
     * @param  array{cantidad: float|int|string, costo_unitario?: float|int|string|null, motivo?: string|null}  $datos
     */
    public function ejecutar(Producto $producto, array $datos, User $usuario): MovimientoInventario
    {
        return DB::transaction(function () use ($producto, $datos, $usuario): MovimientoInventario {
            $actual = Producto::query()
                ->whereKey($producto->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $stockAnterior = Dinero::cantidad($actual->stock_actual);
            $cantidad = Dinero::cantidad($datos['cantidad'] ?? null);

            if (Dinero::comparar($cantidad, 0) <= 0) {
                throw ValidationException::withMessages([
                    'cantidad' => 'La cantidad de entrada debe ser mayor que cero.',
                ]);
            }

            $stockNuevo = Dinero::cantidad(bcadd($stockAnterior, $cantidad, 3));
            $costoActual = Dinero::redondear($actual->costo_promedio);
            $costoEntrada = $costoActual;
            $costoNuevo = $costoActual;

            if (array_key_exists('costo_unitario', $datos) && $datos['costo_unitario'] !== null) {
                $costoEntrada = Dinero::redondear($datos['costo_unitario']);

                if (Dinero::comparar($costoEntrada, 0) < 0) {
                    throw ValidationException::withMessages([
                        'costo_unitario' => 'El costo unitario no puede ser negativo.',
                    ]);
                }

                $valorActual = bcmul($stockAnterior, $costoActual, 6);
                $valorEntrada = bcmul($cantidad, $costoEntrada, 6);
                $costoNuevo = Dinero::redondear(
                    bcdiv(bcadd($valorActual, $valorEntrada, 6), $stockNuevo, 6)
                );
            }

            $actual->update([
                'stock_actual' => $stockNuevo,
                'costo_promedio' => $costoNuevo,
            ]);

            return MovimientoInventario::create([
                'empresa_id' => $actual->empresa_id,
                'producto_id' => $actual->id,
                'tipo' => TipoMovimientoInventario::Entrada,
                'cantidad' => $cantidad,
                'costo_unitario' => $costoEntrada,
                'stock_anterior' => $stockAnterior,
                'stock_nuevo' => $stockNuevo,
                'motivo' => $this->limpiarMotivo($datos['motivo'] ?? null),
                'created_by' => $usuario->id,
            ])->load('producto', 'usuario');
        });
    }

    private function limpiarMotivo(?string $motivo): ?string
    {
        $motivo = trim((string) $motivo);

        return $motivo === '' ? null : $motivo;
    }
}
