<?php

declare(strict_types=1);

namespace App\Actions\Dashboard;

use App\Enums\EstadoOrden;
use App\Enums\EstadoProforma;
use App\Enums\TipoProformaItem;
use App\Models\Cliente;
use App\Models\CuentaPorCobrar;
use App\Models\MovimientoInventario;
use App\Models\OrdenItem;
use App\Models\OrdenServicio;
use App\Models\PagoCuenta;
use App\Models\Producto;
use App\Models\Proforma;
use App\Models\Servicio;
use App\Support\Dinero;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Construye el resumen operativo del dashboard interno.
 *
 * Todo el dinero se acumula con {@see Dinero}; las fechas de cobranza se
 * comparan en formato date-only para no mezclar zonas horarias.
 */
final class ObtenerResumenDashboard
{
    /**
     * @return array<string, mixed>
     */
    public function ejecutar(): array
    {
        $hoy = CarbonImmutable::today();
        $inicioMes = $hoy->startOfMonth()->toDateString();
        $finMes = $hoy->endOfMonth()->toDateString();
        $inicioMesTimestamp = $hoy->startOfMonth()->startOfDay();
        $finMesTimestamp = $hoy->endOfMonth()->endOfDay();

        $porEstadoProforma = $this->contarProformasPorEstado();
        $montoCotizadoMes = $this->sumarProformas(
            [EstadoProforma::Borrador, EstadoProforma::Enviada],
            $inicioMes,
            $finMes,
        );
        $montoAceptadoMes = $this->sumarProformas(
            [EstadoProforma::Aceptada],
            $inicioMes,
            $finMes,
        );

        $ordenes = $this->resumenOrdenes($inicioMesTimestamp, $finMesTimestamp);
        $cobros = $this->resumenCobros($hoy, $inicioMes, $finMes);
        $inventario = $this->resumenInventario($inicioMesTimestamp, $finMesTimestamp);

        return [
            'clientes' => Cliente::query()->count(),
            'servicios' => Servicio::query()->count(),
            'proformas' => Proforma::query()->count(),
            'proformas_por_estado' => $porEstadoProforma,
            'monto_proformas_mes' => (float) $montoCotizadoMes,
            'monto_cotizado_mes' => (float) $montoCotizadoMes,
            'monto_aceptado_mes' => (float) $montoAceptadoMes,
            'ordenes_pendientes' => $ordenes['activas'],
            'saldo_por_cobrar' => $cobros['saldo'],
            'ordenes' => $ordenes,
            'inventario' => $inventario,
            'cobros' => $cobros,
            'ingresos_mes' => $cobros['cobrado_mes'],
            'servicios_mas_solicitados' => $this->serviciosMasSolicitados(),
            'actividad_reciente' => $this->actividadReciente(),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function contarProformasPorEstado(): array
    {
        $porEstado = array_fill_keys(
            array_map(fn (EstadoProforma $estado): string => $estado->value, EstadoProforma::cases()),
            0,
        );

        Proforma::query()
            ->selectRaw('estado, count(*) as total')
            ->groupBy('estado')
            ->pluck('total', 'estado')
            ->each(function ($total, $estado) use (&$porEstado): void {
                $porEstado[(string) $estado] = (int) $total;
            });

        return $porEstado;
    }

    /**
     * @param  array<int, EstadoProforma>  $estados
     */
    private function sumarProformas(array $estados, string $inicioMes, string $finMes): string
    {
        return Dinero::redondear(
            (string) (Proforma::query()
                ->whereBetween('fecha_emision', [$inicioMes, $finMes])
                ->whereIn('estado', array_map(fn (EstadoProforma $estado): string => $estado->value, $estados))
                ->sum('total') ?? 0)
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function resumenOrdenes(CarbonImmutable $inicioMes, CarbonImmutable $finMes): array
    {
        $porEstado = array_fill_keys(
            array_map(fn (EstadoOrden $estado): string => $estado->value, EstadoOrden::cases()),
            0,
        );

        OrdenServicio::query()
            ->selectRaw('estado, count(*) as total')
            ->groupBy('estado')
            ->pluck('total', 'estado')
            ->each(function ($total, $estado) use (&$porEstado): void {
                $porEstado[(string) $estado] = (int) $total;
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
            'por_estado' => $porEstado,
            'finalizadas_mes' => OrdenServicio::query()
                ->where('estado', EstadoOrden::Finalizada->value)
                ->whereBetween('fecha_fin', [$inicioMes, $finMes])
                ->count(),
            'cerradas_mes' => OrdenServicio::query()
                ->whereNotNull('cerrada_at')
                ->whereBetween('cerrada_at', [$inicioMes, $finMes])
                ->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function resumenCobros(CarbonImmutable $hoy, string $inicioMes, string $finMes): array
    {
        $cuentasConSaldo = CuentaPorCobrar::query()
            ->where('saldo', '>', 0)
            ->get(['saldo', 'fecha_vencimiento']);

        $aging = [
            'al_dia' => '0.00',
            'd1_30' => '0.00',
            'd31_60' => '0.00',
            'd61_90' => '0.00',
            'd90_plus' => '0.00',
        ];

        $saldo = '0.00';
        $montoVencido = '0.00';
        $vencidas = 0;

        foreach ($cuentasConSaldo as $cuenta) {
            $saldoCuenta = (string) $cuenta->saldo;
            $saldo = Dinero::sumar($saldo, $saldoCuenta);

            if ($cuenta->fecha_vencimiento === null) {
                $aging['al_dia'] = Dinero::sumar($aging['al_dia'], $saldoCuenta);

                continue;
            }

            $vencimiento = CarbonImmutable::parse($cuenta->fecha_vencimiento->toDateString());

            if ($vencimiento->greaterThanOrEqualTo($hoy)) {
                $aging['al_dia'] = Dinero::sumar($aging['al_dia'], $saldoCuenta);

                continue;
            }

            $vencidas++;
            $montoVencido = Dinero::sumar($montoVencido, $saldoCuenta);

            $diasVencidos = (int) $vencimiento->startOfDay()->diffInDays($hoy->startOfDay(), true);

            $franja = match (true) {
                $diasVencidos <= 30 => 'd1_30',
                $diasVencidos <= 60 => 'd31_60',
                $diasVencidos <= 90 => 'd61_90',
                default => 'd90_plus',
            };

            $aging[$franja] = Dinero::sumar($aging[$franja], $saldoCuenta);
        }

        $cobradoMes = Dinero::redondear(
            (string) (PagoCuenta::query()
                ->whereNull('anulado_at')
                ->whereBetween('fecha_pago', [$inicioMes, $finMes])
                ->sum('monto') ?? 0)
        );

        return [
            'cuentas' => CuentaPorCobrar::query()->count(),
            'pendientes' => $cuentasConSaldo->count(),
            'vencidas' => $vencidas,
            'saldo' => (float) $saldo,
            'monto_vencido' => (float) $montoVencido,
            'cobrado_mes' => (float) $cobradoMes,
            'monto_total' => (float) Dinero::redondear(
                (string) (CuentaPorCobrar::query()->sum('monto_total') ?? 0)
            ),
            'aging' => array_map(
                fn (string $monto): float => (float) $monto,
                $aging,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function resumenInventario(CarbonImmutable $inicioMes, CarbonImmutable $finMes): array
    {
        $productosBajoStock = Producto::query()
            ->whereColumn('stock_actual', '<=', 'stock_minimo')
            ->orderByRaw('(stock_minimo - stock_actual) DESC')
            ->orderBy('nombre')
            ->orderBy('id')
            ->limit(5)
            ->get(['id', 'nombre', 'stock_actual', 'stock_minimo', 'unidad_medida'])
            ->map(fn (Producto $producto): array => [
                'id' => $producto->id,
                'nombre' => $producto->nombre,
                'stock_actual' => (float) $producto->stock_actual,
                'stock_minimo' => (float) $producto->stock_minimo,
                'unidad_medida' => $producto->unidad_medida,
            ])
            ->values()
            ->all();

        $valorInventario = Producto::query()
            ->selectRaw('coalesce(sum(stock_actual * costo_promedio), 0) as valor')
            ->value('valor');

        return [
            'productos' => Producto::query()->count(),
            'stock_bajo' => Producto::query()
                ->whereColumn('stock_actual', '<=', 'stock_minimo')
                ->count(),
            'valor_inventario' => (float) Dinero::redondear((string) ($valorInventario ?? 0)),
            'movimientos_mes' => MovimientoInventario::query()
                ->whereBetween('created_at', [$inicioMes, $finMes])
                ->count(),
            'productos_bajo_stock' => $productosBajoStock,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function serviciosMasSolicitados(): array
    {
        return OrdenItem::query()
            ->select('servicio_id')
            ->selectRaw('SUM(cantidad) as cantidad_total')
            ->selectRaw('SUM(total_linea) as total_solicitado')
            ->where('tipo', TipoProformaItem::Servicio->value)
            ->whereNotNull('servicio_id')
            ->whereHas('orden', fn ($query) => $query->where('estado', '!=', EstadoOrden::Cancelada->value))
            ->groupBy('servicio_id')
            ->orderByDesc('cantidad_total')
            ->orderBy('servicio_id')
            ->limit(5)
            ->with('servicio:id,nombre')
            ->get()
            ->map(fn (OrdenItem $item): array => [
                'servicio_id' => (int) $item->servicio_id,
                'nombre' => $item->servicio?->nombre,
                'cantidad' => (float) $item->cantidad_total,
                'total' => (float) Dinero::redondear((string) $item->total_solicitado),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function actividadReciente(): array
    {
        return collect()
            ->concat($this->actividadProformas())
            ->concat($this->actividadOrdenes())
            ->concat($this->actividadPagos())
            ->concat($this->actividadMovimientos())
            ->concat($this->actividadClientes())
            ->sortByDesc('fecha')
            ->take(10)
            ->values()
            ->all();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function actividadProformas(): Collection
    {
        return Proforma::query()
            ->latest('created_at')
            ->latest('id')
            ->limit(10)
            ->get()
            ->map(fn (Proforma $proforma): array => [
                'tipo' => 'proforma',
                'id' => $proforma->id,
                'descripcion' => $proforma->numero,
                'estado' => $proforma->estado?->value,
                'monto' => (float) $proforma->total,
                'fecha' => $proforma->created_at?->toISOString(),
            ]);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function actividadOrdenes(): Collection
    {
        return OrdenServicio::query()
            ->latest('created_at')
            ->latest('id')
            ->limit(10)
            ->get()
            ->map(fn (OrdenServicio $orden): array => [
                'tipo' => 'orden',
                'id' => $orden->id,
                'descripcion' => $orden->numero,
                'estado' => $orden->estado?->value,
                'monto' => null,
                'fecha' => $orden->created_at?->toISOString(),
            ]);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function actividadPagos(): Collection
    {
        return PagoCuenta::query()
            ->with(['cuenta:id,numero', 'usuario:id,name'])
            ->latest('created_at')
            ->latest('id')
            ->limit(10)
            ->get()
            ->map(fn (PagoCuenta $pago): array => [
                'tipo' => 'pago',
                'id' => $pago->id,
                'descripcion' => 'Pago '.($pago->cuenta?->numero ?? ''),
                'estado' => null,
                'monto' => (float) $pago->monto,
                'cuenta_id' => $pago->cuenta_id,
                'fecha' => $pago->fecha_pago?->toISOString() ?? $pago->created_at?->toISOString(),
                'usuario' => $pago->usuario?->name,
            ]);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function actividadMovimientos(): Collection
    {
        return MovimientoInventario::query()
            ->with(['producto:id,nombre', 'usuario:id,name'])
            ->latest('created_at')
            ->latest('id')
            ->limit(10)
            ->get()
            ->map(fn (MovimientoInventario $movimiento): array => [
                'tipo' => 'movimiento',
                'id' => $movimiento->id,
                'descripcion' => trim(sprintf(
                    '%s %s',
                    $movimiento->tipo?->value ?? '',
                    $movimiento->producto?->nombre ?? '',
                )),
                'estado' => null,
                'monto' => (float) ($movimiento->costo_unitario ?? 0),
                'producto_id' => $movimiento->producto_id,
                'fecha' => $movimiento->created_at?->toISOString(),
                'usuario' => $movimiento->usuario?->name,
            ]);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function actividadClientes(): Collection
    {
        return Cliente::query()
            ->latest('created_at')
            ->latest('id')
            ->limit(10)
            ->get()
            ->map(fn (Cliente $cliente): array => [
                'tipo' => 'cliente',
                'id' => $cliente->id,
                'descripcion' => $cliente->nombre_razon,
                'estado' => $cliente->estado?->value,
                'monto' => null,
                'fecha' => $cliente->created_at?->toISOString(),
            ]);
    }
}
