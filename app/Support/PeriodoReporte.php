<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Rango de fechas de un reporte, en días calendario de Bolivia.
 *
 * Las columnas `date` (fecha_programada, fecha_pago...) se comparan con
 * {@see desde()} / {@see hasta()}; las columnas de fecha y hora (aceptada_at,
 * created_at...) con {@see inicio()} / {@see fin()}, que ya vienen en la zona
 * horaria con la que la aplicación guarda los timestamps.
 */
final class PeriodoReporte
{
    public const ZONA_HORARIA = 'America/La_Paz';

    private function __construct(
        private readonly CarbonImmutable $desde,
        private readonly CarbonImmutable $hasta,
    ) {}

    /**
     * Sin fechas, el mes actual en Bolivia. Con una sola, el mes de esa fecha
     * completa la otra.
     */
    public static function crear(?string $desde, ?string $hasta): self
    {
        $inicio = $desde !== null ? self::fecha($desde) : null;
        $final = $hasta !== null ? self::fecha($hasta) : null;

        if ($inicio === null && $final === null) {
            $hoy = self::hoy();

            return new self($hoy->startOfMonth(), $hoy->endOfMonth()->startOfDay());
        }

        $inicio ??= $final->startOfMonth();
        $final ??= $inicio->endOfMonth()->startOfDay();

        return new self($inicio, $final);
    }

    public static function hoy(): CarbonImmutable
    {
        return CarbonImmutable::now(self::ZONA_HORARIA)->startOfDay();
    }

    /** Primer día del periodo (YYYY-MM-DD). */
    public function fechaDesde(): string
    {
        return $this->desde->toDateString();
    }

    /** Último día del periodo, incluido (YYYY-MM-DD). */
    public function fechaHasta(): string
    {
        return $this->hasta->toDateString();
    }

    /**
     * Filtra una columna `date` por el periodo. Se compara por día porque
     * algunos motores guardan la fecha con hora ("2026-11-30 00:00:00").
     *
     * @template TQuery of \Illuminate\Contracts\Database\Query\Builder
     *
     * @param  TQuery  $query
     * @return TQuery
     */
    public function filtrarFecha($query, string $columna)
    {
        return $query
            ->whereDate($columna, '>=', $this->fechaDesde())
            ->whereDate($columna, '<=', $this->fechaHasta());
    }

    /** 00:00:00 del primer día en Bolivia, en la zona de la aplicación. */
    public function inicio(): CarbonImmutable
    {
        return $this->desde->startOfDay()->setTimezone(config('app.timezone'));
    }

    /** 23:59:59 del último día en Bolivia, en la zona de la aplicación. */
    public function fin(): CarbonImmutable
    {
        return $this->hasta->endOfDay()->setTimezone(config('app.timezone'));
    }

    /** Día calendario de Bolivia de un timestamp guardado. */
    public static function diaLocal(?CarbonInterface $momento): ?string
    {
        return $momento?->copy()->setTimezone(self::ZONA_HORARIA)->toDateString();
    }

    /**
     * @return array{desde: string, hasta: string}
     */
    public function toArray(): array
    {
        return ['desde' => $this->fechaDesde(), 'hasta' => $this->fechaHasta()];
    }

    private static function fecha(string $valor): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $valor, self::ZONA_HORARIA);
    }
}
