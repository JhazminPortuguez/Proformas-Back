<?php

declare(strict_types=1);

namespace App\Actions\Reportes;

use App\Enums\EstadoProforma;
use App\Models\ProformaItem;
use App\Models\Promocion;
use App\Support\Csv;
use App\Support\DescuentoPromocion;
use App\Support\Dinero;
use App\Support\PeriodoReporte;
use App\Support\Reportes\DocumentoReporte;
use App\Support\Reportes\TablaReporte;
use Illuminate\Database\Eloquent\Builder;

/**
 * Ronda C, parte 1: reporte de promociones.
 *
 * Un uso es una línea con promoción en una proforma emitida en el periodo
 * (fecha de emisión) que ya salió del borrador: Enviada, Aceptada o
 * Rechazada. "Descontado" suma el descuento de esas líneas; "En ventas", solo
 * el de las proformas aceptadas.
 */
final class ReportePromociones
{
    /**
     * @return array<string, mixed>
     */
    public function ejecutar(PeriodoReporte $periodo, ?int $clienteId = null): array
    {
        $filas = $this->filas($periodo, $clienteId);

        return [
            'periodo' => $periodo->toArray(),
            'usos' => array_sum(array_column($filas, 'usos')),
            'proformas' => $this->proformasConPromocion($periodo, $clienteId),
            'descontado' => (float) Dinero::sumar('0', ...array_column($filas, 'descontado')),
            'descontado_ventas' => (float) Dinero::sumar('0', ...array_column($filas, 'descontado_ventas')),
            'promociones' => array_map(fn (array $fila): array => [
                ...$fila,
                'descontado' => (float) $fila['descontado'],
                'descontado_ventas' => (float) $fila['descontado_ventas'],
            ], $filas),
        ];
    }

    /**
     * @return array{encabezados: list<string>, filas: list<list<string|int|null>>}
     */
    public function detalle(PeriodoReporte $periodo, ?int $clienteId = null): array
    {
        return [
            'encabezados' => ['Promoción', 'Tipo', 'Valor', 'Usos', 'Proformas', 'Descontado (Bs)', 'Descontado en ventas aceptadas (Bs)'],
            'filas' => array_map(fn (array $fila): array => [
                $fila['nombre'],
                $fila['tipo_etiqueta'],
                $fila['etiqueta'],
                $fila['usos'],
                $fila['proformas'],
                Csv::numero($fila['descontado']),
                Csv::numero($fila['descontado_ventas']),
            ], $this->filas($periodo, $clienteId)),
        ];
    }

    public function documento(PeriodoReporte $periodo, ?int $clienteId = null): DocumentoReporte
    {
        $datos = $this->ejecutar($periodo, $clienteId);
        $filas = $this->filas($periodo, $clienteId);

        return new DocumentoReporte(
            titulo: 'Reporte de promociones',
            clave: 'promociones',
            resumen: [
                DocumentoReporte::dato('Usos de promociones', $datos['usos']),
                DocumentoReporte::dato('Proformas con promoción', $datos['proformas']),
                DocumentoReporte::dato('Descontado en total', $datos['descontado'], TablaReporte::DINERO),
                DocumentoReporte::dato('Descontado en ventas aceptadas', $datos['descontado_ventas'], TablaReporte::DINERO),
            ],
            tablas: [
                new TablaReporte(
                    'Promociones usadas',
                    [
                        TablaReporte::columna('Promoción'),
                        TablaReporte::columna('Tipo'),
                        TablaReporte::columna('Valor'),
                        TablaReporte::columna('Usos', TablaReporte::ENTERO),
                        TablaReporte::columna('Proformas', TablaReporte::ENTERO),
                        TablaReporte::columna('Descontado', TablaReporte::DINERO),
                        TablaReporte::columna('En ventas aceptadas', TablaReporte::DINERO),
                    ],
                    array_map(fn (array $fila): array => [
                        $fila['nombre'],
                        $fila['tipo_etiqueta'],
                        $fila['etiqueta'],
                        $fila['usos'],
                        $fila['proformas'],
                        $fila['descontado'],
                        $fila['descontado_ventas'],
                    ], $filas),
                    $filas === [] ? null : [
                        'Total: '.count($filas).' promociones',
                        null,
                        null,
                        $datos['usos'],
                        $datos['proformas'],
                        Dinero::redondear((string) $datos['descontado']),
                        Dinero::redondear((string) $datos['descontado_ventas']),
                    ],
                    'No se usaron promociones en el periodo.',
                ),
            ],
            nota: 'Cuenta las líneas con promoción de las proformas emitidas en el periodo que ya se enviaron (sin borradores).',
        );
    }

    /**
     * Una fila por promoción usada, la que más descontó primero.
     *
     * @return list<array{id: int, nombre: string, tipo: string, tipo_etiqueta: string, etiqueta: string, usos: int, proformas: int, descontado: string, descontado_ventas: string}>
     */
    private function filas(PeriodoReporte $periodo, ?int $clienteId): array
    {
        $lineas = $this->lineas($periodo, $clienteId)
            ->with('proforma:id,estado')
            ->get(['id', 'proforma_id', 'promocion_id', 'descuento_promocion']);

        $promociones = Promocion::query()
            ->whereIn('id', $lineas->pluck('promocion_id')->unique()->values())
            ->get()
            ->keyBy('id');

        $filas = [];

        foreach ($lineas->groupBy('promocion_id') as $promocionId => $grupo) {
            $promocion = $promociones->get((int) $promocionId);

            if ($promocion === null) {
                continue;
            }

            $ventas = $grupo->filter(fn (ProformaItem $linea): bool => $linea->proforma?->estado === EstadoProforma::Aceptada);

            $filas[] = [
                'id' => $promocion->id,
                'nombre' => $promocion->nombre,
                'tipo' => $promocion->tipo->value,
                'tipo_etiqueta' => $promocion->tipo->etiqueta(),
                'etiqueta' => DescuentoPromocion::etiqueta($promocion->tipo, $promocion->valor),
                'usos' => $grupo->count(),
                'proformas' => $grupo->pluck('proforma_id')->unique()->count(),
                'descontado' => Dinero::sumar('0', ...$grupo->map(fn ($linea): string => (string) $linea->descuento_promocion)),
                'descontado_ventas' => Dinero::sumar('0', ...$ventas->map(fn ($linea): string => (string) $linea->descuento_promocion)),
            ];
        }

        usort($filas, fn (array $a, array $b): int => Dinero::comparar($b['descontado'], $a['descontado'])
            ?: strcmp($a['nombre'], $b['nombre']));

        return $filas;
    }

    private function proformasConPromocion(PeriodoReporte $periodo, ?int $clienteId): int
    {
        return $this->lineas($periodo, $clienteId)->distinct()->count('proforma_id');
    }

    /** @return Builder<ProformaItem> */
    private function lineas(PeriodoReporte $periodo, ?int $clienteId): Builder
    {
        return ProformaItem::query()
            ->whereNotNull('promocion_id')
            ->whereHas('proforma', fn (Builder $proforma) => $proforma
                ->where('estado', '!=', EstadoProforma::Borrador->value)
                ->where(fn (Builder $query) => $periodo->filtrarFecha($query, 'fecha_emision'))
                ->when($clienteId, fn (Builder $query, int $id) => $query->where('cliente_id', $id)));
    }
}
