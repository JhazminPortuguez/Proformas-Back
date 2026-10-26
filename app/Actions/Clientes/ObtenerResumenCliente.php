<?php

declare(strict_types=1);

namespace App\Actions\Clientes;

use App\Enums\EstadoOrden;
use App\Enums\EstadoProforma;
use App\Enums\TipoProformaItem;
use App\Models\Cliente;
use App\Models\CuentaPorCobrar;
use App\Models\OrdenItem;
use App\Models\OrdenServicio;
use App\Models\PagoCuenta;
use App\Models\Proforma;
use App\Support\Dinero;
use Carbon\CarbonImmutable;

/**
 * Agrega el historial comercial y operativo de un cliente.
 *
 * Todas las consultas respetan el scope global de empresa; el dinero se
 * acumula con {@see Dinero} y las fechas de cobranza se comparan date-only.
 */
final class ObtenerResumenCliente
{
    /**
     * @return array<string, mixed>
     */
    public function ejecutar(Cliente $cliente): array
    {
        return [
            'proformas' => $this->resumenProformas($cliente),
            'ordenes' => $this->resumenOrdenes($cliente),
            'cobros' => $this->resumenCobros($cliente),
            'servicios' => $this->resumenServicios($cliente),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function resumenProformas(Cliente $cliente): array
    {
        $porEstado = array_fill_keys(
            array_map(fn (EstadoProforma $estado): string => $estado->value, EstadoProforma::cases()),
            0,
        );

        $filas = Proforma::query()
            ->where('cliente_id', $cliente->getKey())
            ->selectRaw('estado, count(*) as total, coalesce(sum(total), 0) as monto')
            ->groupBy('estado')
            ->get();

        $montoTotal = '0.00';
        $montoAceptado = '0.00';

        foreach ($filas as $fila) {
            $estado = (string) $fila->getRawOriginal('estado');
            $monto = Dinero::redondear((string) $fila->monto);

            $porEstado[$estado] = (int) $fila->total;
            $montoTotal = Dinero::sumar($montoTotal, $monto);

            if ($estado === EstadoProforma::Aceptada->value) {
                $montoAceptado = Dinero::sumar($montoAceptado, $monto);
            }
        }

        return [
            'total' => array_sum($porEstado),
            'por_estado' => $porEstado,
            'monto_total' => (float) $montoTotal,
            'monto_aceptado' => (float) $montoAceptado,
        ];
    }

    /**
     * @return array<string, int>
     */
    private function resumenOrdenes(Cliente $cliente): array
    {
        $porEstado = array_fill_keys(
            array_map(fn (EstadoOrden $estado): string => $estado->value, EstadoOrden::cases()),
            0,
        );

        OrdenServicio::query()
            ->where('cliente_id', $cliente->getKey())
            ->selectRaw('estado, count(*) as total')
            ->groupBy('estado')
            ->get()
            ->each(function ($fila) use (&$porEstado): void {
                $porEstado[(string) $fila->getRawOriginal('estado')] = (int) $fila->total;
            });

        $activas = $porEstado[EstadoOrden::Pendiente->value]
            + $porEstado[EstadoOrden::Asignada->value]
            + $porEstado[EstadoOrden::EnProceso->value];

        return [
            'total' => array_sum($porEstado),
            'activas' => $activas,
            'pendientes' => $porEstado[EstadoOrden::Pendiente->value],
            'asignadas' => $porEstado[EstadoOrden::Asignada->value],
            'en_proceso' => $porEstado[EstadoOrden::EnProceso->value],
            'finalizadas' => $porEstado[EstadoOrden::Finalizada->value],
            'canceladas' => $porEstado[EstadoOrden::Cancelada->value],
        ];
    }

    /**
     * @return array<string, float|int>
     */
    private function resumenCobros(Cliente $cliente): array
    {
        $agregado = CuentaPorCobrar::query()
            ->where('cliente_id', $cliente->getKey())
            ->selectRaw('count(*) as cuentas, coalesce(sum(saldo), 0) as saldo, coalesce(sum(monto_total), 0) as monto_total')
            ->first();

        $vencidas = CuentaPorCobrar::query()
            ->where('cliente_id', $cliente->getKey())
            ->where('saldo', '>', 0)
            ->whereDate('fecha_vencimiento', '<', CarbonImmutable::today()->toDateString())
            ->count();

        $totalPagado = PagoCuenta::query()
            ->whereNull('anulado_at')
            ->whereHas('cuenta', fn ($query) => $query->where('cliente_id', $cliente->getKey()))
            ->sum('monto');

        return [
            'cuentas' => (int) ($agregado?->cuentas ?? 0),
            'saldo' => (float) Dinero::redondear((string) ($agregado?->saldo ?? 0)),
            'vencidas' => $vencidas,
            'monto_total' => (float) Dinero::redondear((string) ($agregado?->monto_total ?? 0)),
            'total_pagado' => (float) Dinero::redondear((string) ($totalPagado ?? 0)),
        ];
    }

    /**
     * @return array<string, float|int>
     */
    private function resumenServicios(Cliente $cliente): array
    {
        $agregado = OrdenItem::query()
            ->where('tipo', TipoProformaItem::Servicio->value)
            ->whereHas('orden', fn ($query) => $query
                ->where('cliente_id', $cliente->getKey())
                ->where('estado', '!=', EstadoOrden::Cancelada->value))
            ->selectRaw('coalesce(sum(cantidad), 0) as realizados, coalesce(sum(total_linea), 0) as monto')
            ->first();

        return [
            'realizados' => (float) Dinero::redondear((string) ($agregado?->realizados ?? 0), Dinero::ESCALA_CANTIDAD),
            'monto' => (float) Dinero::redondear((string) ($agregado?->monto ?? 0)),
        ];
    }
}
