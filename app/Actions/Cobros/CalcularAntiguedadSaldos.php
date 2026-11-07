<?php

declare(strict_types=1);

namespace App\Actions\Cobros;

use App\Models\CuentaPorCobrar;
use App\Support\Dinero;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Saldos pendientes por antigüedad: al día, 1-30, 31-60, 61-90 y más de 90
 * días vencidos. Lo usan el dashboard y el reporte de cuentas por cobrar.
 *
 * Las fechas se comparan como días calendario (sin hora) y los montos se
 * acumulan con {@see Dinero}.
 */
final class CalcularAntiguedadSaldos
{
    public const AL_DIA = 'al_dia';

    public const FRANJAS = [self::AL_DIA, 'd1_30', 'd31_60', 'd61_90', 'd90_plus'];

    /**
     * @param  iterable<CuentaPorCobrar>  $cuentas  Cuentas con saldo pendiente.
     * @return array{
     *     saldo: string,
     *     monto_vencido: string,
     *     vencidas: int,
     *     aging: array<string, string>,
     *     cuentas: list<array{cuenta: CuentaPorCobrar, franja: string, dias_vencida: int}>
     * }
     */
    public function ejecutar(iterable $cuentas, CarbonInterface $hoy): array
    {
        $aging = array_fill_keys(self::FRANJAS, '0.00');
        $saldo = '0.00';
        $montoVencido = '0.00';
        $vencidas = 0;
        $detalle = [];

        foreach ($cuentas as $cuenta) {
            $saldoCuenta = (string) $cuenta->saldo;
            ['franja' => $franja, 'dias' => $dias] = $this->clasificar($cuenta->fecha_vencimiento, $hoy);

            $saldo = Dinero::sumar($saldo, $saldoCuenta);
            $aging[$franja] = Dinero::sumar($aging[$franja], $saldoCuenta);

            if ($franja !== self::AL_DIA) {
                $vencidas++;
                $montoVencido = Dinero::sumar($montoVencido, $saldoCuenta);
            }

            $detalle[] = ['cuenta' => $cuenta, 'franja' => $franja, 'dias_vencida' => $dias];
        }

        return [
            'saldo' => $saldo,
            'monto_vencido' => $montoVencido,
            'vencidas' => $vencidas,
            'aging' => $aging,
            'cuentas' => $detalle,
        ];
    }

    /**
     * Sin vencimiento o con vencimiento hoy o después: al día.
     *
     * @return array{franja: string, dias: int}
     */
    public function clasificar(?CarbonInterface $vencimiento, CarbonInterface $hoy): array
    {
        if ($vencimiento === null) {
            return ['franja' => self::AL_DIA, 'dias' => 0];
        }

        $dia = CarbonImmutable::parse($vencimiento->toDateString(), 'UTC');
        $referencia = CarbonImmutable::parse($hoy->toDateString(), 'UTC');

        if ($dia->greaterThanOrEqualTo($referencia)) {
            return ['franja' => self::AL_DIA, 'dias' => 0];
        }

        $dias = (int) $dia->diffInDays($referencia, true);

        return [
            'franja' => match (true) {
                $dias <= 30 => 'd1_30',
                $dias <= 60 => 'd31_60',
                $dias <= 90 => 'd61_90',
                default => 'd90_plus',
            },
            'dias' => $dias,
        ];
    }
}
