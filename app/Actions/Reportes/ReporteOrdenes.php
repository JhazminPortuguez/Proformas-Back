<?php

declare(strict_types=1);

namespace App\Actions\Reportes;

use App\Enums\EstadoOrden;
use App\Models\OrdenServicio;
use App\Support\Dinero;
use App\Support\PeriodoReporte;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Reporte A: órdenes de servicio del periodo por estado, por operario y
 * cumplimiento de la fecha programada.
 *
 * Las órdenes del periodo se eligen con {@see OrdenServicio::scopeDelPeriodo()}.
 */
final class ReporteOrdenes
{
    public const A_TIEMPO = 'A_TIEMPO';

    public const CON_ATRASO = 'CON_ATRASO';

    public const SIN_FECHA = 'SIN_FECHA';

    /**
     * @return array<string, mixed>
     */
    public function ejecutar(PeriodoReporte $periodo, ?int $clienteId = null, ?int $operarioId = null): array
    {
        $ordenes = $this->ordenes($periodo, $clienteId, $operarioId);

        $porEstado = array_fill_keys(
            array_map(fn (EstadoOrden $estado): string => $estado->value, EstadoOrden::cases()),
            0,
        );

        foreach ($ordenes as $orden) {
            $porEstado[$orden->estado->value]++;
        }

        $cumplimiento = [self::A_TIEMPO => 0, self::CON_ATRASO => 0, self::SIN_FECHA => 0];
        $atrasadas = [];

        foreach ($ordenes as $orden) {
            $resultado = $this->cumplimiento($orden);

            if ($resultado === null) {
                continue;
            }

            $cumplimiento[$resultado['estado']]++;

            if ($resultado['estado'] === self::CON_ATRASO) {
                $atrasadas[] = [
                    ...$this->resumen($orden),
                    'fecha_programada' => $orden->fecha_programada?->toDateString(),
                    'fecha_finalizacion' => $resultado['fecha_finalizacion'],
                    'dias_atraso' => $resultado['dias_atraso'],
                ];
            }
        }

        usort($atrasadas, fn (array $a, array $b): int => [$b['dias_atraso'], $a['numero']] <=> [$a['dias_atraso'], $b['numero']]);

        $medibles = $cumplimiento[self::A_TIEMPO] + $cumplimiento[self::CON_ATRASO];

        return [
            'periodo' => $periodo->toArray(),
            'total' => $ordenes->count(),
            'por_estado' => $porEstado,
            'por_operario' => $this->porOperario($ordenes),
            'cumplimiento' => [
                'a_tiempo' => $cumplimiento[self::A_TIEMPO],
                'con_atraso' => $cumplimiento[self::CON_ATRASO],
                'sin_fecha' => $cumplimiento[self::SIN_FECHA],
                'porcentaje_a_tiempo' => $medibles === 0
                    ? null
                    : (float) Dinero::redondear(
                        bcdiv((string) ($cumplimiento[self::A_TIEMPO] * 100), (string) $medibles, 6),
                        1,
                    ),
            ],
            'atrasadas' => $atrasadas,
        ];
    }

    /**
     * Una fila por orden del periodo para el CSV y, debajo, el resumen por
     * operario (separado por una fila vacía).
     *
     * @return array{encabezados: list<string>, filas: list<list<string|int|null>>}
     */
    public function detalle(PeriodoReporte $periodo, ?int $clienteId = null, ?int $operarioId = null): array
    {
        $ordenes = $this->ordenes($periodo, $clienteId, $operarioId);

        $filas = $ordenes
            ->map(function (OrdenServicio $orden): array {
                $cumplimiento = $this->cumplimiento($orden);

                return [
                    $orden->numero,
                    $orden->cliente?->nombre_razon,
                    $orden->cliente?->nit_ci,
                    $orden->operario?->name ?? 'Sin asignar',
                    $orden->estado->etiqueta(),
                    $orden->fecha_programada?->toDateString(),
                    PeriodoReporte::diaLocal($orden->fecha_fin),
                    match ($cumplimiento['estado'] ?? null) {
                        self::A_TIEMPO => 'A tiempo',
                        self::CON_ATRASO => 'Con atraso',
                        self::SIN_FECHA => 'Sin fecha programada',
                        default => '',
                    },
                    $cumplimiento['dias_atraso'] ?? null,
                ];
            })
            ->values()
            ->all();

        if ($filas !== []) {
            $filas[] = [];
            $filas[] = ['Operario', 'Pendientes', 'Asignadas', 'En proceso', 'Finalizadas', 'Canceladas', 'Total'];

            foreach ($this->porOperario($ordenes) as $fila) {
                $filas[] = [
                    $fila['operario'] ?? 'Sin asignar',
                    $fila['pendientes'],
                    $fila['asignadas'],
                    $fila['en_proceso'],
                    $fila['finalizadas'],
                    $fila['canceladas'],
                    $fila['total'],
                ];
            }
        }

        return [
            'encabezados' => [
                'Código',
                'Cliente',
                'NIT/CI',
                'Operario',
                'Estado',
                'Fecha programada',
                'Fecha de finalización',
                'Cumplimiento',
                'Días de atraso',
            ],
            'filas' => $filas,
        ];
    }

    /**
     * @return Collection<int, OrdenServicio>
     */
    private function ordenes(PeriodoReporte $periodo, ?int $clienteId, ?int $operarioId): Collection
    {
        return OrdenServicio::query()
            ->with(['cliente:id,nombre_razon,nit_ci', 'operario:id,name'])
            ->delPeriodo($periodo)
            ->when($clienteId, fn (Builder $query, int $id) => $query->where('cliente_id', $id))
            ->when($operarioId, fn (Builder $query, int $id) => $query->where('operario_id', $id))
            ->orderBy('numero')
            ->orderBy('id')
            ->get();
    }

    /**
     * Solo las órdenes finalizadas se miden: se compara el día de finalización
     * (hora de Bolivia) con la fecha programada.
     *
     * @return array{estado: string, fecha_finalizacion: string|null, dias_atraso: int|null}|null
     */
    private function cumplimiento(OrdenServicio $orden): ?array
    {
        if ($orden->estado !== EstadoOrden::Finalizada) {
            return null;
        }

        $finalizacion = PeriodoReporte::diaLocal($orden->fecha_fin);

        if ($orden->fecha_programada === null) {
            return ['estado' => self::SIN_FECHA, 'fecha_finalizacion' => $finalizacion, 'dias_atraso' => null];
        }

        if ($finalizacion === null) {
            return null;
        }

        $programada = CarbonImmutable::parse($orden->fecha_programada->toDateString());
        $fin = CarbonImmutable::parse($finalizacion);

        if ($fin->lessThanOrEqualTo($programada)) {
            return ['estado' => self::A_TIEMPO, 'fecha_finalizacion' => $finalizacion, 'dias_atraso' => null];
        }

        return [
            'estado' => self::CON_ATRASO,
            'fecha_finalizacion' => $finalizacion,
            'dias_atraso' => (int) $programada->diffInDays($fin, true),
        ];
    }

    /**
     * @param  Collection<int, OrdenServicio>  $ordenes
     * @return list<array<string, mixed>>
     */
    private function porOperario(Collection $ordenes): array
    {
        return $ordenes
            ->groupBy(fn (OrdenServicio $orden): string => (string) ($orden->operario_id ?? ''))
            ->map(function (Collection $grupo): array {
                $primera = $grupo->first();

                return [
                    'operario_id' => $primera->operario_id,
                    'operario' => $primera->operario?->name,
                    'pendientes' => $grupo->where('estado', EstadoOrden::Pendiente)->count(),
                    'asignadas' => $grupo->where('estado', EstadoOrden::Asignada)->count(),
                    'en_proceso' => $grupo->where('estado', EstadoOrden::EnProceso)->count(),
                    'finalizadas' => $grupo->where('estado', EstadoOrden::Finalizada)->count(),
                    'canceladas' => $grupo->where('estado', EstadoOrden::Cancelada)->count(),
                    'total' => $grupo->count(),
                ];
            })
            // Los operarios por nombre; las órdenes sin operario, al final.
            ->sortBy(fn (array $fila): string => $fila['operario_id'] === null ? "\u{FFFF}" : mb_strtolower((string) $fila['operario']))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function resumen(OrdenServicio $orden): array
    {
        return [
            'id' => $orden->id,
            'numero' => $orden->numero,
            'cliente' => [
                'nombre_razon' => $orden->cliente?->nombre_razon,
                'nit_ci' => $orden->cliente?->nit_ci,
            ],
            'operario' => $orden->operario?->name,
        ];
    }
}
