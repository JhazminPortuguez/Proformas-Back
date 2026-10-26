<?php

declare(strict_types=1);

namespace App\Actions\Proformas;

use App\Enums\TipoProformaItem;
use App\Models\Producto;
use App\Models\Servicio;
use App\Support\Dinero;
use Illuminate\Validation\ValidationException;

final class ConstruirLineasProforma
{
    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array{lineas: array<int, array<string, mixed>>, subtotal: string}
     */
    public function ejecutar(array $items): array
    {
        $tipos = [];
        $productoIds = [];
        $servicioIds = [];

        foreach ($items as $indice => $item) {
            $tipo = $item['tipo'] ?? TipoProformaItem::Material->value;
            $tipoEnum = $tipo instanceof TipoProformaItem
                ? $tipo
                : TipoProformaItem::tryFrom((string) $tipo);

            if ($tipoEnum === null) {
                throw ValidationException::withMessages([
                    "items.$indice.tipo" => 'El tipo de línea no es válido.',
                ]);
            }

            $tipos[$indice] = $tipoEnum;

            if ($tipoEnum === TipoProformaItem::Material) {
                $productoIds[] = (int) ($item['producto_id'] ?? 0);
            }

            if ($tipoEnum === TipoProformaItem::Servicio) {
                $servicioIds[] = (int) ($item['servicio_id'] ?? 0);
            }
        }

        $productos = Producto::query()
            ->whereIn('id', array_values(array_filter(array_unique($productoIds))))
            ->get()
            ->keyBy('id');

        $servicios = Servicio::query()
            ->whereIn('id', array_values(array_filter(array_unique($servicioIds))))
            ->get()
            ->keyBy('id');

        $lineas = [];

        foreach ($items as $indice => $item) {
            $tipo = $tipos[$indice];
            $lineas[] = match ($tipo) {
                TipoProformaItem::Material => $this->lineaMaterial($item, $productos->get((int) ($item['producto_id'] ?? 0)), $indice),
                TipoProformaItem::Servicio => $this->lineaServicio($item, $servicios->get((int) ($item['servicio_id'] ?? 0)), $indice),
                TipoProformaItem::Libre => $this->lineaLibre($item, $indice),
            };
        }

        $subtotal = Dinero::sumar(...array_column($lineas, 'total_linea'));

        if (Dinero::mayorQue($subtotal, Dinero::MONTO_MAXIMO)) {
            throw ValidationException::withMessages([
                'items' => 'El subtotal de la proforma excede el máximo permitido.',
            ]);
        }

        return [
            'lineas' => $lineas,
            'subtotal' => $subtotal,
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function lineaMaterial(array $item, ?Producto $producto, int $indice): array
    {
        if ($producto === null) {
            throw ValidationException::withMessages([
                "items.$indice.producto_id" => 'El producto seleccionado no existe.',
            ]);
        }

        if (! $producto->activo) {
            throw ValidationException::withMessages([
                "items.$indice.producto_id" => 'El producto seleccionado no está activo.',
            ]);
        }

        $cantidad = $this->cantidad($item, $indice);
        $precio = Dinero::redondear($producto->precio_unitario);

        return [
            'tipo' => TipoProformaItem::Material->value,
            'producto_id' => $producto->id,
            'servicio_id' => null,
            'nombre' => $producto->nombre,
            'descripcion' => $this->descripcion($item),
            'unidad_medida' => $producto->unidad_medida,
            'cantidad' => $cantidad,
            'precio_unitario' => $precio,
            'total_linea' => $this->totalLinea($cantidad, $precio, $indice),
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function lineaServicio(array $item, ?Servicio $servicio, int $indice): array
    {
        if ($servicio === null) {
            throw ValidationException::withMessages([
                "items.$indice.servicio_id" => 'El servicio seleccionado no existe.',
            ]);
        }

        if (! $servicio->activo) {
            throw ValidationException::withMessages([
                "items.$indice.servicio_id" => 'El servicio seleccionado no está activo.',
            ]);
        }

        $cantidad = $this->cantidad($item, $indice);
        $precio = Dinero::redondear($servicio->precio_base);

        return [
            'tipo' => TipoProformaItem::Servicio->value,
            'producto_id' => null,
            'servicio_id' => $servicio->id,
            'nombre' => $servicio->nombre,
            'descripcion' => $this->descripcion($item),
            'unidad_medida' => $item['unidad_medida'] ?? 'servicio',
            'cantidad' => $cantidad,
            'precio_unitario' => $precio,
            'total_linea' => $this->totalLinea($cantidad, $precio, $indice),
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function lineaLibre(array $item, int $indice): array
    {
        $descripcion = $this->descripcion($item);

        if ($descripcion === null || $descripcion === '') {
            throw ValidationException::withMessages([
                "items.$indice.descripcion" => 'La línea libre requiere una descripción.',
            ]);
        }

        if (! array_key_exists('precio_unitario', $item) || $item['precio_unitario'] === null || $item['precio_unitario'] === '') {
            throw ValidationException::withMessages([
                "items.$indice.precio_unitario" => 'La línea libre requiere un precio unitario.',
            ]);
        }

        $cantidad = $this->cantidad($item, $indice);
        $precio = Dinero::redondear($item['precio_unitario']);

        if (Dinero::comparar($precio, 0) < 0) {
            throw ValidationException::withMessages([
                "items.$indice.precio_unitario" => 'El precio unitario no puede ser negativo.',
            ]);
        }

        return [
            'tipo' => TipoProformaItem::Libre->value,
            'producto_id' => null,
            'servicio_id' => null,
            'nombre' => mb_substr($descripcion, 0, 200),
            'descripcion' => $descripcion,
            'unidad_medida' => $item['unidad_medida'] ?? null,
            'cantidad' => $cantidad,
            'precio_unitario' => $precio,
            'total_linea' => $this->totalLinea($cantidad, $precio, $indice),
        ];
    }

    private function totalLinea(string $cantidad, string $precio, int $indice): string
    {
        $total = Dinero::totalLinea($cantidad, $precio);

        if (Dinero::mayorQue($total, Dinero::MONTO_MAXIMO)) {
            throw ValidationException::withMessages([
                "items.$indice.precio_unitario" => 'El total de la línea excede el máximo permitido.',
            ]);
        }

        return $total;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function cantidad(array $item, int $indice): string
    {
        $cantidad = Dinero::cantidad($item['cantidad'] ?? 0);

        if (Dinero::comparar($cantidad, 0) <= 0) {
            throw ValidationException::withMessages([
                "items.$indice.cantidad" => 'La cantidad debe ser mayor que cero.',
            ]);
        }

        return $cantidad;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function descripcion(array $item): ?string
    {
        $descripcion = $item['descripcion'] ?? null;

        if ($descripcion === null) {
            return null;
        }

        $descripcion = trim((string) $descripcion);

        return $descripcion === '' ? null : $descripcion;
    }
}
