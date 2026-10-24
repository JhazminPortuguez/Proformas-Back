<?php

declare(strict_types=1);

namespace App\Actions\Proformas;

use App\Models\Producto;
use App\Support\Dinero;
use Illuminate\Validation\ValidationException;

final class ConstruirLineasProforma
{
    /**
     * @param  array<int, array{producto_id: int|string, cantidad: int|string, descripcion?: string|null}>  $items
     * @return array{lineas: array<int, array<string, mixed>>, subtotal: float}
     */
    public function ejecutar(array $items): array
    {
        $ids = collect($items)->pluck('producto_id')->map(fn ($id) => (int) $id)->unique()->all();

        $productos = Producto::query()
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        $lineas = [];

        foreach ($items as $indice => $item) {
            $producto = $productos->get((int) $item['producto_id']);

            if (! $producto) {
                throw ValidationException::withMessages([
                    "items.$indice.producto_id" => 'El producto seleccionado no existe.',
                ]);
            }

            if (! $producto->activo) {
                throw ValidationException::withMessages([
                    "items.$indice.producto_id" => 'El producto seleccionado no está activo.',
                ]);
            }

            $cantidad = (int) $item['cantidad'];
            $precio = (float) $producto->precio_unitario;

            $lineas[] = [
                'producto_id' => $producto->id,
                'descripcion' => $item['descripcion'] ?? null,
                'cantidad' => $cantidad,
                'precio_unitario' => $precio,
                'total_linea' => Dinero::totalLinea($cantidad, $precio),
            ];
        }

        return [
            'lineas' => $lineas,
            'subtotal' => Dinero::redondear(array_sum(array_column($lineas, 'total_linea'))),
        ];
    }
}
