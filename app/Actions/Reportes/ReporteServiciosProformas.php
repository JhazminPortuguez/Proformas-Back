<?php

declare(strict_types=1);

namespace App\Actions\Reportes;

use App\Enums\EstadoOrden;
use App\Enums\EstadoProforma;
use App\Enums\TipoProformaItem;
use App\Models\OrdenItem;
use App\Models\Proforma;
use App\Support\Csv;
use App\Support\Dinero;
use App\Support\PeriodoReporte;
use Illuminate\Database\Eloquent\Builder;

/**
 * Reporte B: monto cotizado y aceptado, tasa de aceptación de proformas y
 * servicios más solicitados del periodo.
 *
 * - Cotizado: proformas emitidas en el periodo que se enviaron al cliente
 *   (enviadas, aceptadas o rechazadas); los borradores no cuentan.
 * - Aceptado: proformas aceptadas dentro del periodo (por `aceptada_at`).
 * - Tasa: aceptadas / (aceptadas + rechazadas) del periodo.
 * - Servicios: líneas de servicio de las órdenes del periodo que no se
 *   cancelaron, es decir, lo que realmente se ejecutó.
 */
final class ReporteServiciosProformas
{
    /**
     * @return array<string, mixed>
     */
    public function ejecutar(PeriodoReporte $periodo, ?int $clienteId = null): array
    {
        $cotizadas = $this->proformas($clienteId)
            ->where(fn (Builder $query) => $periodo->filtrarFecha($query, 'fecha_emision'))
            ->whereIn('estado', [
                EstadoProforma::Enviada->value,
                EstadoProforma::Aceptada->value,
                EstadoProforma::Rechazada->value,
            ])
            ->get(['total', 'estado']);

        $aceptadas = $this->proformas($clienteId)
            ->where('estado', EstadoProforma::Aceptada->value)
            ->whereBetween('aceptada_at', [$periodo->inicio(), $periodo->fin()])
            ->pluck('total');

        $rechazadas = $this->proformas($clienteId)
            ->where('estado', EstadoProforma::Rechazada->value)
            ->whereBetween('rechazada_at', [$periodo->inicio(), $periodo->fin()])
            ->count();

        $respondidas = $aceptadas->count() + $rechazadas;

        return [
            'periodo' => $periodo->toArray(),
            'proformas' => [
                'cotizado' => (float) Dinero::sumar('0', ...$cotizadas->pluck('total')->map(fn ($total): string => (string) $total)),
                'cotizadas' => $cotizadas->count(),
                'aceptado' => (float) Dinero::sumar('0', ...$aceptadas->map(fn ($total): string => (string) $total)),
                'aceptadas' => $aceptadas->count(),
                'rechazadas' => $rechazadas,
                'en_espera' => $cotizadas->where('estado', EstadoProforma::Enviada)->count(),
                'tasa_aceptacion' => $respondidas === 0
                    ? null
                    : (float) Dinero::redondear(
                        bcdiv((string) ($aceptadas->count() * 100), (string) $respondidas, 6),
                        1,
                    ),
            ],
            'servicios' => $this->servicios($periodo, $clienteId),
        ];
    }

    /**
     * Una fila por servicio, para el CSV.
     *
     * @return array{encabezados: list<string>, filas: list<list<string|int|null>>}
     */
    public function detalle(PeriodoReporte $periodo, ?int $clienteId = null): array
    {
        return [
            'encabezados' => ['Servicio', 'Categoría', 'Cantidad', 'Monto (Bs)'],
            'filas' => array_map(fn (array $servicio): array => [
                $servicio['nombre'],
                $servicio['categoria'],
                Csv::cantidad($servicio['cantidad_exacta']),
                Csv::numero($servicio['monto_exacto']),
            ], $this->serviciosExactos($periodo, $clienteId)),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function servicios(PeriodoReporte $periodo, ?int $clienteId): array
    {
        return array_map(fn (array $servicio): array => [
            'servicio_id' => $servicio['servicio_id'],
            'nombre' => $servicio['nombre'],
            'categoria' => $servicio['categoria'],
            'cantidad' => (float) $servicio['cantidad_exacta'],
            'monto' => (float) $servicio['monto_exacto'],
        ], $this->serviciosExactos($periodo, $clienteId));
    }

    /**
     * Ranking por cantidad y, a igual cantidad, por monto. Los montos se
     * acumulan con bcmath.
     *
     * @return list<array{servicio_id: int, nombre: string|null, categoria: string|null, cantidad_exacta: string, monto_exacto: string}>
     */
    private function serviciosExactos(PeriodoReporte $periodo, ?int $clienteId): array
    {
        $lineas = OrdenItem::query()
            ->where('tipo', TipoProformaItem::Servicio->value)
            ->whereNotNull('servicio_id')
            ->whereHas('orden', fn (Builder $query) => $query
                ->delPeriodo($periodo)
                ->where('estado', '!=', EstadoOrden::Cancelada->value)
                ->when($clienteId, fn (Builder $query, int $id) => $query->where('cliente_id', $id)))
            ->with('servicio:id,nombre,categoria')
            ->get(['id', 'orden_id', 'servicio_id', 'cantidad', 'total_linea']);

        $servicios = [];

        foreach ($lineas as $linea) {
            $id = (int) $linea->servicio_id;

            $servicios[$id] ??= [
                'servicio_id' => $id,
                'nombre' => $linea->servicio?->nombre,
                'categoria' => $linea->servicio?->categoria,
                'cantidad_exacta' => '0',
                'monto_exacto' => '0.00',
            ];

            $servicios[$id]['cantidad_exacta'] = Dinero::cantidad(
                bcadd(Dinero::cantidad($servicios[$id]['cantidad_exacta']), Dinero::cantidad($linea->cantidad), Dinero::ESCALA_CANTIDAD)
            );
            $servicios[$id]['monto_exacto'] = Dinero::sumar($servicios[$id]['monto_exacto'], (string) $linea->total_linea);
        }

        $servicios = array_values($servicios);

        usort($servicios, fn (array $a, array $b): int => Dinero::comparar($b['cantidad_exacta'], $a['cantidad_exacta'])
            ?: Dinero::comparar($b['monto_exacto'], $a['monto_exacto'])
            ?: strcmp(mb_strtolower((string) $a['nombre']), mb_strtolower((string) $b['nombre'])));

        return $servicios;
    }

    /**
     * @return Builder<Proforma>
     */
    private function proformas(?int $clienteId): Builder
    {
        return Proforma::query()
            ->when($clienteId, fn (Builder $query, int $id) => $query->where('cliente_id', $id));
    }
}
