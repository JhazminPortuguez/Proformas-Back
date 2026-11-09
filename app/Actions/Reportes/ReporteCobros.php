<?php

declare(strict_types=1);

namespace App\Actions\Reportes;

use App\Actions\Cobros\CalcularAntiguedadSaldos;
use App\Models\CuentaPorCobrar;
use App\Models\PagoCuenta;
use App\Support\Csv;
use App\Support\Dinero;
use App\Support\PeriodoReporte;
use Illuminate\Database\Eloquent\Builder;

/**
 * Reporte D: lo cobrado en el periodo y los saldos pendientes por antigüedad.
 *
 * - Cobrado: pagos no anulados con fecha de pago dentro del periodo.
 * - Saldo pendiente y antigüedad: foto de hoy (hora de Bolivia), con el
 *   mismo cálculo que el dashboard ({@see CalcularAntiguedadSaldos}).
 */
final class ReporteCobros
{
    public const FRANJA_ETIQUETA = [
        'al_dia' => 'Al día',
        'd1_30' => '1-30 días',
        'd31_60' => '31-60 días',
        'd61_90' => '61-90 días',
        'd90_plus' => 'Más de 90 días',
    ];

    public function __construct(
        private readonly CalcularAntiguedadSaldos $antiguedad,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function ejecutar(PeriodoReporte $periodo, ?int $clienteId = null): array
    {
        $pagos = PagoCuenta::query()
            ->whereNull('anulado_at')
            ->where(fn (Builder $query) => $periodo->filtrarFecha($query, 'fecha_pago'))
            ->when($clienteId, fn (Builder $query, int $id) => $query->whereHas('cuenta', fn (Builder $cuenta) => $cuenta->where('cliente_id', $id)))
            ->pluck('monto');

        $saldos = $this->saldos($clienteId);

        return [
            'periodo' => $periodo->toArray(),
            'cobrado' => (float) Dinero::sumar('0', ...$pagos->map(fn ($monto): string => (string) $monto)),
            'pagos' => $pagos->count(),
            'saldo_pendiente' => (float) $saldos['saldo'],
            'cuentas_con_saldo' => count($saldos['cuentas']),
            'vencidas' => $saldos['vencidas'],
            'monto_vencido' => (float) $saldos['monto_vencido'],
            'aging' => array_map(fn (string $monto): float => (float) $monto, $saldos['aging']),
            'cuentas' => array_map(fn (array $fila): array => [
                'id' => $fila['cuenta']->id,
                'numero' => $fila['cuenta']->numero,
                'concepto' => $fila['cuenta']->concepto?->value ?? 'SERVICIO',
                'concepto_etiqueta' => $fila['cuenta']->concepto?->etiqueta() ?? 'Servicio',
                'cliente' => [
                    'nombre_razon' => $fila['cuenta']->cliente?->nombre_razon,
                    'nit_ci' => $fila['cuenta']->cliente?->nit_ci,
                ],
                'fecha_vencimiento' => $fila['cuenta']->fecha_vencimiento?->toDateString(),
                'dias_vencida' => $fila['dias_vencida'],
                'saldo' => (float) Dinero::redondear((string) $fila['cuenta']->saldo),
                'franja' => $fila['franja'],
            ], $saldos['cuentas']),
        ];
    }

    /**
     * Una fila por cuenta con saldo, para el CSV.
     *
     * @return array{encabezados: list<string>, filas: list<list<string|int|null>>}
     */
    public function detalle(?int $clienteId = null): array
    {
        return [
            'encabezados' => ['Número', 'Concepto', 'Cliente', 'NIT/CI', 'Vencimiento', 'Días vencida', 'Saldo (Bs)', 'Antigüedad'],
            'filas' => array_map(fn (array $fila): array => [
                $fila['cuenta']->numero,
                $fila['cuenta']->concepto?->etiqueta() ?? 'Servicio',
                $fila['cuenta']->cliente?->nombre_razon,
                $fila['cuenta']->cliente?->nit_ci,
                $fila['cuenta']->fecha_vencimiento?->toDateString(),
                $fila['dias_vencida'],
                Csv::numero(Dinero::redondear((string) $fila['cuenta']->saldo)),
                self::FRANJA_ETIQUETA[$fila['franja']],
            ], $this->saldos($clienteId)['cuentas']),
        ];
    }

    /**
     * Cuentas con saldo, las más vencidas primero.
     *
     * @return array{saldo: string, monto_vencido: string, vencidas: int, aging: array<string, string>, cuentas: list<array{cuenta: CuentaPorCobrar, franja: string, dias_vencida: int}>}
     */
    private function saldos(?int $clienteId): array
    {
        $cuentas = CuentaPorCobrar::query()
            ->where('saldo', '>', 0)
            ->when($clienteId, fn (Builder $query, int $id) => $query->where('cliente_id', $id))
            ->with('cliente:id,nombre_razon,nit_ci')
            ->orderBy('numero')
            ->get(['id', 'numero', 'concepto', 'cliente_id', 'fecha_vencimiento', 'saldo']);

        $resultado = $this->antiguedad->ejecutar($cuentas, PeriodoReporte::hoy());

        usort($resultado['cuentas'], fn (array $a, array $b): int => $b['dias_vencida'] <=> $a['dias_vencida']
            ?: strcmp((string) $a['cuenta']->numero, (string) $b['cuenta']->numero));

        return $resultado;
    }
}
