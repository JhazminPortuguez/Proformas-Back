<?php

declare(strict_types=1);

namespace App\Actions\Ordenes;

use App\Enums\EstadoOrden;
use App\Enums\TipoMovimientoInventario;
use App\Enums\TipoOrdenEvento;
use App\Enums\TipoProformaItem;
use App\Models\MovimientoInventario;
use App\Models\OrdenItem;
use App\Models\OrdenServicio;
use App\Models\Producto;
use App\Models\User;
use App\Support\Dinero;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ConsumirMaterialesOrden
{
    /**
     * Consume materiales de una orden, de forma total o parcial.
     *
     * Se registra un único evento CONSUMO por operación con el detalle de las
     * líneas afectadas, en lugar de un evento por línea, para no inflar la
     * bitácora cuando se consumen varios materiales a la vez.
     *
     * @param  array<int, array{orden_item_id: int|string, cantidad: float|int|string}>  $items
     */
    public function ejecutar(OrdenServicio $orden, array $items, User $usuario): OrdenServicio
    {
        return DB::transaction(function () use ($orden, $items, $usuario): OrdenServicio {
            $actual = OrdenServicio::query()
                ->whereKey($orden->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->validarEstado($actual);

            // Bloquear primero las líneas y después los productos en orden
            // ascendente de producto_id evita interbloqueos entre consumos
            // concurrentes que comparten materiales.
            $lineas = OrdenItem::query()
                ->where('orden_id', $actual->id)
                ->orderBy('producto_id')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $solicitadas = $this->seleccionarLineas($lineas, $items);
            $consumosPorProducto = $this->agruparPorProducto($solicitadas);

            $productos = Producto::query()
                ->whereIn('id', array_keys($consumosPorProducto))
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $this->validarStock($consumosPorProducto, $productos);

            $momento = now();
            $resumen = [];

            foreach ($solicitadas as $solicitud) {
                /** @var OrdenItem $linea */
                $linea = $solicitud['linea'];
                $cantidad = $solicitud['cantidad'];
                $producto = $productos->get((int) $linea->producto_id);

                $stockAnterior = Dinero::cantidad($producto->stock_actual);
                $stockNuevo = Dinero::cantidad(bcsub($stockAnterior, $cantidad, 3));
                $producto->update(['stock_actual' => $stockNuevo]);

                MovimientoInventario::create([
                    'empresa_id' => $producto->empresa_id,
                    'producto_id' => $producto->id,
                    'orden_id' => $actual->id,
                    'orden_item_id' => $linea->id,
                    'tipo' => TipoMovimientoInventario::Consumo,
                    'cantidad' => Dinero::cantidad(bcsub('0', $cantidad, 3)),
                    'costo_unitario' => Dinero::redondear($producto->costo_promedio),
                    'stock_anterior' => $stockAnterior,
                    'stock_nuevo' => $stockNuevo,
                    'motivo' => 'Consumo de materiales de la orden '.$actual->numero,
                    'created_by' => $usuario->id,
                ]);

                $consumida = Dinero::cantidad(
                    bcadd(Dinero::cantidad($linea->cantidad_consumida), $cantidad, 3)
                );
                $completo = Dinero::comparar($consumida, $linea->cantidad) >= 0;

                $linea->update([
                    'cantidad_consumida' => $consumida,
                    'consumido_at' => $completo ? $momento : null,
                ]);

                $resumen[] = [
                    'orden_item_id' => $linea->id,
                    'producto_id' => $producto->id,
                    'producto' => $producto->nombre,
                    'cantidad' => (float) $cantidad,
                    'stock_anterior' => (float) $stockAnterior,
                    'stock_nuevo' => (float) $stockNuevo,
                ];
            }

            $actual->update(['updated_by' => $usuario->id]);

            $actual->eventos()->create([
                'tipo' => TipoOrdenEvento::Consumo,
                'descripcion' => $this->descripcionEvento($actual, $resumen),
                'estado_anterior' => null,
                'estado_nuevo' => null,
                'user_id' => $usuario->id,
                'metadata' => [
                    'cantidad_lineas' => count($resumen),
                    'items' => $resumen,
                ],
            ]);

            return $actual->fresh()
                ->load(
                    'cliente',
                    'proforma',
                    'operario',
                    'items.producto',
                    'items.servicio',
                    'eventos.usuario',
                    'cerrador',
                    'creador',
                    'editor',
                )
                ->loadCount('items');
        });
    }

    private function validarEstado(OrdenServicio $orden): void
    {
        if ($orden->cerrada_at !== null) {
            throw ValidationException::withMessages([
                'estado' => 'La orden está cerrada y no admite consumos de materiales.',
            ]);
        }

        if (! in_array($orden->estado, [EstadoOrden::EnProceso, EstadoOrden::Finalizada], true)) {
            throw ValidationException::withMessages([
                'estado' => 'Solo se pueden consumir materiales de una orden en proceso o finalizada.',
            ]);
        }
    }

    /**
     * @param  Collection<int, OrdenItem>  $lineas
     * @param  array<int, array<string, mixed>>  $items
     * @return list<array{linea: OrdenItem, cantidad: string}>
     */
    private function seleccionarLineas(Collection $lineas, array $items): array
    {
        $pendientes = $lineas
            ->filter(fn (OrdenItem $linea): bool => $linea->tipo === TipoProformaItem::Material
                && $linea->producto_id !== null
                && ! $linea->estaConsumido())
            ->values();

        if ($items === []) {
            if ($pendientes->isEmpty()) {
                throw ValidationException::withMessages([
                    'items' => 'La orden no tiene materiales pendientes de consumo.',
                ]);
            }

            return $pendientes
                ->map(fn (OrdenItem $linea): array => [
                    'linea' => $linea,
                    'cantidad' => $linea->cantidadPendiente(),
                ])
                ->all();
        }

        $seleccionadas = [];
        $vistas = [];

        foreach ($items as $indice => $item) {
            $itemId = (int) ($item['orden_item_id'] ?? 0);

            if (isset($vistas[$itemId])) {
                throw ValidationException::withMessages([
                    "items.{$indice}.orden_item_id" => 'La línea está repetida en la solicitud.',
                ]);
            }

            $vistas[$itemId] = true;

            $linea = $lineas->firstWhere('id', $itemId);

            if (! $linea instanceof OrdenItem) {
                throw ValidationException::withMessages([
                    "items.{$indice}.orden_item_id" => 'La línea no pertenece a esta orden.',
                ]);
            }

            if ($linea->tipo !== TipoProformaItem::Material) {
                throw ValidationException::withMessages([
                    "items.{$indice}.orden_item_id" => 'Solo se pueden consumir líneas de tipo MATERIAL.',
                ]);
            }

            if ($linea->producto_id === null) {
                throw ValidationException::withMessages([
                    "items.{$indice}.orden_item_id" => 'La línea de material no tiene un producto asociado.',
                ]);
            }

            if ($linea->estaConsumido()) {
                throw ValidationException::withMessages([
                    "items.{$indice}.orden_item_id" => 'La línea ya fue consumida por completo.',
                ]);
            }

            $cantidad = Dinero::cantidad($item['cantidad'] ?? null);

            if (Dinero::comparar($cantidad, 0) <= 0) {
                throw ValidationException::withMessages([
                    "items.{$indice}.cantidad" => 'La cantidad a consumir debe ser mayor que cero.',
                ]);
            }

            $pendiente = $linea->cantidadPendiente();

            if (Dinero::comparar($cantidad, $pendiente) > 0) {
                throw ValidationException::withMessages([
                    "items.{$indice}.cantidad" => "La cantidad supera el pendiente de la línea ({$pendiente}).",
                ]);
            }

            $seleccionadas[] = [
                'linea' => $linea,
                'cantidad' => $cantidad,
            ];
        }

        return $seleccionadas;
    }

    /**
     * @param  list<array{linea: OrdenItem, cantidad: string}>  $solicitadas
     * @return array<int, string>
     */
    private function agruparPorProducto(array $solicitadas): array
    {
        $agrupado = [];

        foreach ($solicitadas as $solicitud) {
            $productoId = (int) $solicitud['linea']->producto_id;
            $agrupado[$productoId] = bcadd(
                $agrupado[$productoId] ?? '0',
                $solicitud['cantidad'],
                3,
            );
        }

        return $agrupado;
    }

    /**
     * @param  array<int, string>  $consumosPorProducto
     * @param  Collection<int, Producto>  $productos
     */
    private function validarStock(array $consumosPorProducto, Collection $productos): void
    {
        foreach ($consumosPorProducto as $productoId => $cantidad) {
            $producto = $productos->get($productoId);

            if (! $producto instanceof Producto) {
                throw ValidationException::withMessages([
                    'items' => 'Uno de los productos de la orden ya no está disponible.',
                ]);
            }

            $stock = Dinero::cantidad($producto->stock_actual);

            if (Dinero::comparar($stock, $cantidad) < 0) {
                throw ValidationException::withMessages([
                    'items' => "Stock insuficiente de {$producto->nombre}: disponible {$stock}, solicitado {$cantidad}.",
                ]);
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $resumen
     */
    private function descripcionEvento(OrdenServicio $orden, array $resumen): string
    {
        $lineas = count($resumen);
        $unidades = '0.000';

        foreach ($resumen as $detalle) {
            $unidades = bcadd($unidades, (string) $detalle['cantidad'], 3);
        }

        return sprintf(
            'Consumo de materiales de la orden %s: %d %s, %s unidades.',
            $orden->numero,
            $lineas,
            $lineas === 1 ? 'línea' : 'líneas',
            Dinero::cantidad($unidades),
        );
    }
}
