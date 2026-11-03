<?php

declare(strict_types=1);

namespace App\Actions\Reportes;

use App\Enums\TipoMovimientoInventario;
use App\Models\MovimientoInventario;
use App\Models\Producto;
use App\Support\Csv;
use App\Support\Dinero;
use App\Support\PeriodoReporte;
use Illuminate\Database\Eloquent\Builder;

/**
 * Reporte C: consumo de materiales del periodo y productos con stock bajo.
 *
 * El costo de cada consumo es cantidad × costo_unitario del movimiento, que
 * guarda el costo promedio del producto en el momento de consumir; así el
 * costo histórico no cambia si después cambia el promedio. El stock bajo es
 * una foto de hoy y no depende del periodo ni de los filtros.
 */
final class ReporteInventario
{
    /**
     * @return array<string, mixed>
     */
    public function ejecutar(PeriodoReporte $periodo, ?int $clienteId = null, ?int $operarioId = null): array
    {
        $consumo = $this->consumo($periodo, $clienteId, $operarioId);

        return [
            'periodo' => $periodo->toArray(),
            'consumo' => [
                'costo_total' => (float) Dinero::sumar('0', ...array_column($consumo['productos'], 'costo_exacto')),
                'movimientos' => $consumo['movimientos'],
                'productos' => count($consumo['productos']),
            ],
            'productos' => array_map(fn (array $producto): array => [
                'producto_id' => $producto['producto_id'],
                'nombre' => $producto['nombre'],
                'unidad_medida' => $producto['unidad_medida'],
                'cantidad' => (float) $producto['cantidad_exacta'],
                'costo' => (float) $producto['costo_exacto'],
            ], $consumo['productos']),
            'stock_bajo' => $this->stockBajo(),
        ];
    }

    /**
     * Una fila por producto consumido, para el CSV.
     *
     * @return array{encabezados: list<string>, filas: list<list<string|null>>}
     */
    public function detalle(PeriodoReporte $periodo, ?int $clienteId = null, ?int $operarioId = null): array
    {
        return [
            'encabezados' => ['Producto', 'Unidad', 'Cantidad consumida', 'Costo (Bs)'],
            'filas' => array_map(fn (array $producto): array => [
                $producto['nombre'],
                $producto['unidad_medida'],
                Csv::cantidad($producto['cantidad_exacta']),
                Csv::numero($producto['costo_exacto']),
            ], $this->consumo($periodo, $clienteId, $operarioId)['productos']),
        ];
    }

    /**
     * Consumos agrupados por producto, ordenados por costo y luego por nombre.
     *
     * @return array{movimientos: int, productos: list<array{producto_id: int, nombre: string|null, unidad_medida: string|null, cantidad_exacta: string, costo_exacto: string}>}
     */
    private function consumo(PeriodoReporte $periodo, ?int $clienteId, ?int $operarioId): array
    {
        $movimientos = MovimientoInventario::query()
            ->where('tipo', TipoMovimientoInventario::Consumo->value)
            ->whereBetween('created_at', [$periodo->inicio(), $periodo->fin()])
            ->when($clienteId || $operarioId, fn (Builder $query) => $query->whereHas('orden', fn (Builder $orden) => $orden
                ->when($clienteId, fn (Builder $orden, int $id) => $orden->where('cliente_id', $id))
                ->when($operarioId, fn (Builder $orden, int $id) => $orden->where('operario_id', $id))))
            ->with('producto:id,nombre,unidad_medida')
            ->get(['id', 'producto_id', 'cantidad', 'costo_unitario']);

        $productos = [];

        foreach ($movimientos as $movimiento) {
            $id = (int) $movimiento->producto_id;
            // El consumo se guarda como salida (cantidad negativa).
            $cantidad = ltrim(Dinero::cantidad($movimiento->cantidad), '-');

            $productos[$id] ??= [
                'producto_id' => $id,
                'nombre' => $movimiento->producto?->nombre,
                'unidad_medida' => $movimiento->producto?->unidad_medida,
                'cantidad_exacta' => '0.000',
                'costo_exacto' => '0.00',
            ];

            $productos[$id]['cantidad_exacta'] = Dinero::cantidad(
                bcadd($productos[$id]['cantidad_exacta'], $cantidad, Dinero::ESCALA_CANTIDAD)
            );
            $productos[$id]['costo_exacto'] = Dinero::sumar(
                $productos[$id]['costo_exacto'],
                Dinero::totalLinea($cantidad, (string) ($movimiento->costo_unitario ?? '0')),
            );
        }

        $productos = array_values($productos);

        usort($productos, fn (array $a, array $b): int => Dinero::comparar($b['costo_exacto'], $a['costo_exacto'])
            ?: strcmp(mb_strtolower((string) $a['nombre']), mb_strtolower((string) $b['nombre'])));

        return ['movimientos' => $movimientos->count(), 'productos' => $productos];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function stockBajo(): array
    {
        return Producto::query()
            ->bajoStock()
            ->porFaltante()
            ->get(['id', 'nombre', 'unidad_medida', 'stock_actual', 'stock_minimo'])
            ->map(fn (Producto $producto): array => [
                'id' => $producto->id,
                'nombre' => $producto->nombre,
                'unidad_medida' => $producto->unidad_medida,
                'stock_actual' => (float) $producto->stock_actual,
                'stock_minimo' => (float) $producto->stock_minimo,
                'faltante' => (float) Dinero::cantidad(
                    bcsub(Dinero::cantidad($producto->stock_minimo), Dinero::cantidad($producto->stock_actual), Dinero::ESCALA_CANTIDAD)
                ),
            ])
            ->values()
            ->all();
    }
}
