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

final class RegistrarAjusteInventario
{
    /**
     * @param  array{stock_nuevo: float|int|string, motivo: string}  $datos
     */
    public function ejecutar(Producto $producto, array $datos, User $usuario): MovimientoInventario
    {
        return DB::transaction(function () use ($producto, $datos, $usuario): MovimientoInventario {
            $actual = Producto::query()
                ->whereKey($producto->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $motivo = trim((string) ($datos['motivo'] ?? ''));

            if ($motivo === '') {
                throw ValidationException::withMessages([
                    'motivo' => 'El motivo del ajuste es obligatorio.',
                ]);
            }

            $stockAnterior = Dinero::cantidad($actual->stock_actual);

            if (! array_key_exists('stock_nuevo', $datos) || $datos['stock_nuevo'] === null) {
                throw ValidationException::withMessages([
                    'stock_nuevo' => 'El stock nuevo es obligatorio.',
                ]);
            }

            $stockNuevo = Dinero::cantidad($datos['stock_nuevo']);

            if (Dinero::comparar($stockNuevo, 0) < 0) {
                throw ValidationException::withMessages([
                    'stock_nuevo' => 'El stock nuevo no puede ser negativo.',
                ]);
            }

            $delta = Dinero::cantidad(bcsub($stockNuevo, $stockAnterior, 3));

            if (Dinero::comparar($delta, 0) === 0) {
                throw ValidationException::withMessages([
                    'stock_nuevo' => 'El stock nuevo debe ser distinto del stock actual.',
                ]);
            }

            $costoActual = Dinero::redondear($actual->costo_promedio);

            $actual->update(['stock_actual' => $stockNuevo]);

            return MovimientoInventario::create([
                'empresa_id' => $actual->empresa_id,
                'producto_id' => $actual->id,
                'tipo' => TipoMovimientoInventario::Ajuste,
                'cantidad' => $delta,
                'costo_unitario' => $costoActual,
                'stock_anterior' => $stockAnterior,
                'stock_nuevo' => $stockNuevo,
                'motivo' => $motivo,
                'created_by' => $usuario->id,
            ])->load('producto', 'usuario');
        });
    }
}
