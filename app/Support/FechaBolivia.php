<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Fechas de calendario en la hora de Bolivia.
 *
 * La aplicación guarda los timestamps en UTC. Entre las 20:00 y la medianoche
 * de Bolivia (UTC-4) el día UTC ya es el siguiente, así que "hoy" y el día de
 * un timestamp se calculan siempre en America/La_Paz.
 */
final class FechaBolivia
{
    public const ZONA_HORARIA = 'America/La_Paz';

    /** Medianoche de hoy en Bolivia. */
    public static function hoy(): CarbonImmutable
    {
        return CarbonImmutable::now(self::ZONA_HORARIA)->startOfDay();
    }

    /** Hoy en Bolivia como YYYY-MM-DD. */
    public static function hoyTexto(): string
    {
        return self::hoy()->toDateString();
    }

    /** Día calendario de Bolivia (YYYY-MM-DD) de un timestamp guardado. */
    public static function dia(CarbonInterface $momento): string
    {
        return CarbonImmutable::instance($momento)->setTimezone(self::ZONA_HORARIA)->toDateString();
    }

    /** dd/mm/aaaa de una fecha YYYY-MM-DD o de una fecha de calendario. */
    public static function formato(CarbonInterface|string $fecha): string
    {
        $texto = $fecha instanceof CarbonInterface ? $fecha->toDateString() : substr($fecha, 0, 10);

        return CarbonImmutable::createFromFormat('!Y-m-d', $texto)->format('d/m/Y');
    }
}
