<?php

namespace Tests\Feature;

use App\Enums\EstadoCobro;
use App\Enums\EstadoOrden;
use App\Enums\EstadoProforma;
use App\Enums\Rol;
use App\Enums\TipoMovimientoInventario;
use App\Enums\TipoProformaItem;
use App\Models\Cliente;
use App\Models\CuentaPorCobrar;
use App\Models\MovimientoInventario;
use App\Models\OrdenServicio;
use App\Models\PagoCuenta;
use App\Models\Producto;
use App\Models\Proforma;
use App\Models\Servicio;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_separa_monto_cotizado_y_aceptado(): void
    {
        $admin = User::factory()->create(['rol' => Rol::Admin]);
        $cliente = Cliente::factory()->create();

        Servicio::factory()->count(3)->create();

        foreach ([
            [EstadoProforma::Borrador, 100.50],
            [EstadoProforma::Enviada, 50.25],
            [EstadoProforma::Aceptada, 200.00],
            [EstadoProforma::Rechazada, 999.99],
        ] as [$estado, $total]) {
            Proforma::factory()->create([
                'cliente_id' => $cliente->id,
                'fecha_emision' => now()->toDateString(),
                'estado' => $estado,
                'total' => $total,
            ]);
        }

        $response = $this->actingAs($admin)->getJson('/api/v1/dashboard');

        $response->assertOk()
            ->assertJsonStructure([
                'clientes',
                'servicios',
                'proformas',
                'proformas_por_estado' => [
                    EstadoProforma::Borrador->value,
                    EstadoProforma::Enviada->value,
                    EstadoProforma::Aceptada->value,
                    EstadoProforma::Rechazada->value,
                ],
                'monto_proformas_mes',
                'monto_cotizado_mes',
                'monto_aceptado_mes',
                'ordenes_pendientes',
                'saldo_por_cobrar',
                'ordenes' => [
                    'total',
                    'activas',
                    'pendientes',
                    'asignadas',
                    'en_proceso',
                    'finalizadas',
                    'canceladas',
                    'por_estado' => [
                        EstadoOrden::Pendiente->value,
                        EstadoOrden::Asignada->value,
                        EstadoOrden::EnProceso->value,
                        EstadoOrden::Finalizada->value,
                        EstadoOrden::Cancelada->value,
                    ],
                    'finalizadas_mes',
                    'cerradas_mes',
                ],
                'inventario' => [
                    'productos',
                    'stock_bajo',
                    'valor_inventario',
                    'movimientos_mes',
                    'productos_bajo_stock',
                ],
                'cobros' => [
                    'cuentas',
                    'pendientes',
                    'vencidas',
                    'saldo',
                    'monto_vencido',
                    'cobrado_mes',
                    'monto_total',
                    'aging' => [
                        'al_dia',
                        'd1_30',
                        'd31_60',
                        'd61_90',
                        'd90_plus',
                    ],
                ],
                'ingresos_mes',
                'servicios_mas_solicitados',
                'actividad_reciente',
            ])
            ->assertJsonPath('clientes', 1)
            ->assertJsonPath('servicios', 3)
            ->assertJsonPath('proformas', 4)
            ->assertJsonPath('proformas_por_estado.BORRADOR', 1)
            ->assertJsonPath('proformas_por_estado.ENVIADA', 1)
            ->assertJsonPath('proformas_por_estado.ACEPTADA', 1)
            ->assertJsonPath('proformas_por_estado.RECHAZADA', 1)
            // No incluye borradores rechazados; cotizado = BORRADOR + ENVIADA.
            ->assertJsonPath('monto_proformas_mes', 150.75)
            ->assertJsonPath('monto_cotizado_mes', 150.75)
            ->assertJsonPath('monto_aceptado_mes', 200)
            ->assertJsonPath('ordenes_pendientes', 0)
            ->assertJsonPath('saldo_por_cobrar', 0)
            ->assertJsonPath('ordenes.total', 0)
            ->assertJsonPath('ordenes.activas', 0)
            ->assertJsonPath('inventario.productos', 0)
            ->assertJsonPath('inventario.stock_bajo', 0)
            ->assertJsonPath('inventario.valor_inventario', 0)
            ->assertJsonPath('inventario.movimientos_mes', 0)
            ->assertJsonPath('cobros.cuentas', 0)
            ->assertJsonPath('cobros.pendientes', 0)
            ->assertJsonPath('cobros.vencidas', 0)
            ->assertJsonPath('cobros.saldo', 0)
            ->assertJsonPath('cobros.monto_vencido', 0)
            ->assertJsonPath('cobros.cobrado_mes', 0)
            ->assertJsonPath('cobros.monto_total', 0)
            ->assertJsonPath('cobros.aging.al_dia', 0)
            ->assertJsonPath('cobros.aging.d1_30', 0)
            ->assertJsonPath('cobros.aging.d31_60', 0)
            ->assertJsonPath('cobros.aging.d61_90', 0)
            ->assertJsonPath('cobros.aging.d90_plus', 0)
            ->assertJsonPath('ingresos_mes', 0)
            ->assertJsonPath('servicios_mas_solicitados', []);

        $this->assertCount(5, $response->json('actividad_reciente'));
    }

    public function test_operario_accede_al_dashboard_y_el_entorno_vacio_devuelve_ceros(): void
    {
        $operario = User::factory()->create(['rol' => Rol::Operario]);

        $this->actingAs($operario)->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonPath('clientes', 0)
            ->assertJsonPath('servicios', 0)
            ->assertJsonPath('proformas', 0)
            ->assertJsonPath('proformas_por_estado.BORRADOR', 0)
            ->assertJsonPath('proformas_por_estado.ENVIADA', 0)
            ->assertJsonPath('proformas_por_estado.ACEPTADA', 0)
            ->assertJsonPath('proformas_por_estado.RECHAZADA', 0)
            ->assertJsonPath('monto_proformas_mes', 0)
            ->assertJsonPath('monto_cotizado_mes', 0)
            ->assertJsonPath('monto_aceptado_mes', 0)
            ->assertJsonPath('ordenes_pendientes', 0)
            ->assertJsonPath('saldo_por_cobrar', 0)
            ->assertJsonPath('ordenes.total', 0)
            ->assertJsonPath('ordenes.activas', 0)
            ->assertJsonPath('ordenes.por_estado.PENDIENTE', 0)
            ->assertJsonPath('ordenes.finalizadas_mes', 0)
            ->assertJsonPath('ordenes.cerradas_mes', 0)
            ->assertJsonPath('inventario.productos', 0)
            ->assertJsonPath('inventario.stock_bajo', 0)
            ->assertJsonPath('inventario.valor_inventario', 0)
            ->assertJsonPath('inventario.movimientos_mes', 0)
            ->assertJsonPath('inventario.productos_bajo_stock', [])
            ->assertJsonPath('cobros.cuentas', 0)
            ->assertJsonPath('cobros.pendientes', 0)
            ->assertJsonPath('cobros.vencidas', 0)
            ->assertJsonPath('cobros.saldo', 0)
            ->assertJsonPath('cobros.monto_vencido', 0)
            ->assertJsonPath('cobros.cobrado_mes', 0)
            ->assertJsonPath('cobros.monto_total', 0)
            ->assertJsonPath('cobros.aging.al_dia', 0)
            ->assertJsonPath('cobros.aging.d1_30', 0)
            ->assertJsonPath('cobros.aging.d31_60', 0)
            ->assertJsonPath('cobros.aging.d61_90', 0)
            ->assertJsonPath('cobros.aging.d90_plus', 0)
            ->assertJsonPath('ingresos_mes', 0)
            ->assertJsonPath('servicios_mas_solicitados', [])
            ->assertJsonPath('actividad_reciente', []);
    }

    public function test_dashboard_integra_ordenes_inventario_cobros_servicios_y_actividad(): void
    {
        $admin = User::factory()->create(['rol' => Rol::Admin]);

        $cliente = Cliente::factory()->create(['nombre_razon' => 'Cliente Dashboard']);
        $servicio = Servicio::factory()->create([
            'nombre' => 'Instalación básica',
            'precio_base' => 100,
        ]);

        $proforma = Proforma::factory()->aceptada()->create([
            'cliente_id' => $cliente->id,
            'fecha_emision' => today()->toDateString(),
            'total' => 1000,
        ]);

        $ordenPendiente = OrdenServicio::factory()->paraProforma($proforma)->create([
            'estado' => EstadoOrden::Pendiente,
        ]);

        $ordenParcial = OrdenServicio::factory()->paraProforma($proforma)->finalizada()->create([
            'fecha_fin' => now()->startOfDay()->addHours(10),
            'cerrada_at' => now()->startOfDay()->addHours(11),
        ]);

        $ordenCancelada = OrdenServicio::factory()->paraProforma($proforma)->cancelada()->create();

        $ordenVencida = OrdenServicio::factory()->paraProforma($proforma)->finalizada()->create([
            'fecha_fin' => now()->subMonthNoOverflow()->startOfMonth()->addDays(2),
            'cerrada_at' => now()->subMonthNoOverflow()->startOfMonth()->addDays(3),
        ]);

        $ordenVencida30 = OrdenServicio::factory()->paraProforma($proforma)->finalizada()->create([
            'fecha_fin' => now()->subMonthNoOverflow()->startOfMonth()->addDays(4),
            'cerrada_at' => now()->subMonthNoOverflow()->startOfMonth()->addDays(5),
        ]);

        $this->crearItemServicio($ordenPendiente, $servicio, 2, 200);
        $this->crearItemServicio($ordenParcial, $servicio, 1, 100);
        // La orden cancelada no debe sumar en servicios más solicitados.
        $this->crearItemServicio($ordenCancelada, $servicio, 5, 500);

        $ventilador = Producto::factory()->create([
            'nombre' => 'Ventilador de reemplazo',
            'stock_actual' => 7,
            'stock_minimo' => 10,
            'costo_promedio' => 100,
            'unidad_medida' => 'unidad',
        ]);
        Producto::factory()->create([
            'nombre' => 'Filtro de aire',
            'stock_actual' => 5,
            'stock_minimo' => 10,
            'costo_promedio' => 50,
            'unidad_medida' => 'unidad',
        ]);
        Producto::factory()->create([
            'nombre' => 'Cable de alimentación',
            'stock_actual' => 10,
            'stock_minimo' => 10,
            'costo_promedio' => 20,
        ]);
        Producto::factory()->create([
            'nombre' => 'Motor eléctrico',
            'stock_actual' => 20,
            'stock_minimo' => 5,
            'costo_promedio' => 10,
        ]);
        Producto::factory()->create([
            'nombre' => 'Sensor de temperatura',
            'stock_actual' => 0,
            'stock_minimo' => 0,
            'costo_promedio' => 35,
        ]);
        Producto::factory()->create([
            'nombre' => 'Bomba de agua',
            'stock_actual' => 30,
            'stock_minimo' => 5,
            'costo_promedio' => 5,
        ]);

        MovimientoInventario::factory()->create([
            'producto_id' => $ventilador->id,
            'tipo' => TipoMovimientoInventario::Entrada,
            'cantidad' => 2,
            'costo_unitario' => 100,
            'stock_anterior' => 5,
            'stock_nuevo' => 7,
            'created_by' => $admin->id,
            'created_at' => now(),
        ]);

        MovimientoInventario::factory()->create([
            'producto_id' => $ventilador->id,
            'tipo' => TipoMovimientoInventario::Entrada,
            'cantidad' => 1,
            'costo_unitario' => 100,
            'stock_anterior' => 4,
            'stock_nuevo' => 5,
            'created_by' => $admin->id,
            'created_at' => now()->subMonthNoOverflow()->startOfMonth()->addHours(9),
        ]);

        $cuentaParcial = CuentaPorCobrar::factory()->create([
            'orden_id' => $ordenParcial->id,
            'cliente_id' => $cliente->id,
            'numero' => 'CXC-2026-000001',
            'fecha_emision' => today()->toDateString(),
            'fecha_vencimiento' => today()->addDays(10)->toDateString(),
            'monto_total' => 530,
            'saldo' => 265,
            'estado_cobro' => EstadoCobro::Parcial,
        ]);

        $cuentaVencida = CuentaPorCobrar::factory()->create([
            'orden_id' => $ordenVencida->id,
            'cliente_id' => $cliente->id,
            'numero' => 'CXC-2026-000002',
            'fecha_emision' => today()->subDays(75)->toDateString(),
            'fecha_vencimiento' => today()->subDays(45)->toDateString(),
            'monto_total' => 1000,
            'saldo' => 1000,
            'estado_cobro' => EstadoCobro::Pendiente,
        ]);

        CuentaPorCobrar::factory()->create([
            'orden_id' => $ordenVencida30->id,
            'cliente_id' => $cliente->id,
            'numero' => 'CXC-2026-000003',
            'fecha_emision' => today()->subDays(40)->toDateString(),
            'fecha_vencimiento' => today()->subDays(10)->toDateString(),
            'monto_total' => 200,
            'saldo' => 200,
            'estado_cobro' => EstadoCobro::Pendiente,
        ]);

        PagoCuenta::factory()->create([
            'cuenta_id' => $cuentaParcial->id,
            'monto' => 265,
            'fecha_pago' => today()->toDateString(),
            'created_by' => $admin->id,
        ]);

        $response = $this->actingAs($admin)->getJson('/api/v1/dashboard');

        $response->assertOk()
            ->assertJsonPath('clientes', 1)
            ->assertJsonPath('servicios', 1)
            ->assertJsonPath('proformas', 1)
            ->assertJsonPath('proformas_por_estado.ACEPTADA', 1)
            ->assertJsonPath('monto_proformas_mes', 0)
            ->assertJsonPath('monto_cotizado_mes', 0)
            ->assertJsonPath('monto_aceptado_mes', 1000)
            ->assertJsonPath('ordenes_pendientes', 1)
            ->assertJsonPath('saldo_por_cobrar', 1465)
            ->assertJsonPath('ordenes.total', 5)
            ->assertJsonPath('ordenes.activas', 1)
            ->assertJsonPath('ordenes.pendientes', 1)
            ->assertJsonPath('ordenes.asignadas', 0)
            ->assertJsonPath('ordenes.en_proceso', 0)
            ->assertJsonPath('ordenes.finalizadas', 3)
            ->assertJsonPath('ordenes.canceladas', 1)
            ->assertJsonPath('ordenes.por_estado.PENDIENTE', 1)
            ->assertJsonPath('ordenes.por_estado.ASIGNADA', 0)
            ->assertJsonPath('ordenes.por_estado.EN_PROCESO', 0)
            ->assertJsonPath('ordenes.por_estado.FINALIZADA', 3)
            ->assertJsonPath('ordenes.por_estado.CANCELADA', 1)
            ->assertJsonPath('ordenes.finalizadas_mes', 1)
            ->assertJsonPath('ordenes.cerradas_mes', 1)
            ->assertJsonPath('inventario.productos', 6)
            ->assertJsonPath('inventario.stock_bajo', 4)
            ->assertJsonPath('inventario.valor_inventario', 1500)
            ->assertJsonPath('inventario.movimientos_mes', 1)
            ->assertJsonPath('inventario.productos_bajo_stock.0.nombre', 'Filtro de aire')
            ->assertJsonPath('inventario.productos_bajo_stock.0.stock_actual', 5)
            ->assertJsonPath('inventario.productos_bajo_stock.0.stock_minimo', 10)
            ->assertJsonPath('inventario.productos_bajo_stock.0.unidad_medida', 'unidad')
            ->assertJsonPath('inventario.productos_bajo_stock.1.nombre', 'Ventilador de reemplazo')
            ->assertJsonPath('inventario.productos_bajo_stock.1.stock_actual', 7)
            ->assertJsonPath('cobros.cuentas', 3)
            ->assertJsonPath('cobros.pendientes', 3)
            ->assertJsonPath('cobros.vencidas', 2)
            ->assertJsonPath('cobros.saldo', 1465)
            ->assertJsonPath('cobros.monto_vencido', 1200)
            ->assertJsonPath('cobros.cobrado_mes', 265)
            ->assertJsonPath('cobros.monto_total', 1730)
            ->assertJsonPath('cobros.aging.al_dia', 265)
            ->assertJsonPath('cobros.aging.d1_30', 200)
            ->assertJsonPath('cobros.aging.d31_60', 1000)
            ->assertJsonPath('cobros.aging.d61_90', 0)
            ->assertJsonPath('cobros.aging.d90_plus', 0)
            ->assertJsonPath('ingresos_mes', 265)
            ->assertJsonPath('servicios_mas_solicitados.0.servicio_id', $servicio->id)
            ->assertJsonPath('servicios_mas_solicitados.0.nombre', 'Instalación básica')
            ->assertJsonPath('servicios_mas_solicitados.0.cantidad', 3)
            ->assertJsonPath('servicios_mas_solicitados.0.total', 300);

        $actividad = $response->json('actividad_reciente');

        $this->assertCount(10, $actividad);
        $this->assertSame(1, collect($actividad)->where('tipo', 'pago')->count());
        $this->assertSame(1, collect($actividad)->where('tipo', 'cliente')->count());
        $this->assertSame(5, collect($actividad)->where('tipo', 'orden')->count());
        $this->assertSame(2, collect($actividad)->where('tipo', 'movimiento')->count());

        $pago = collect($actividad)->firstWhere('tipo', 'pago');

        $this->assertSame('Pago CXC-2026-000001', $pago['descripcion']);
        $this->assertSame($cuentaParcial->id, $pago['cuenta_id']);
        $this->assertSame(265, $pago['monto']);
        $this->assertSame($admin->name, $pago['usuario']);
        $this->assertNull($pago['estado']);

        $movimiento = collect($actividad)->firstWhere('tipo', 'movimiento');

        $this->assertSame('ENTRADA Ventilador de reemplazo', $movimiento['descripcion']);
        $this->assertSame($ventilador->id, $movimiento['producto_id']);
        $this->assertSame($admin->name, $movimiento['usuario']);

        $ordenActividad = collect($actividad)->first(
            fn (array $item): bool => $item['tipo'] === 'orden' && $item['id'] === $ordenPendiente->id
        );

        $this->assertNotNull($ordenActividad);
        $this->assertSame($ordenPendiente->numero, $ordenActividad['descripcion']);
        $this->assertSame(EstadoOrden::Pendiente->value, $ordenActividad['estado']);
        $this->assertNull($ordenActividad['monto']);

        $this->assertDatabaseHas('cuentas_por_cobrar', [
            'id' => $cuentaVencida->id,
            'saldo' => 1000,
        ]);
    }

    public function test_pagos_anulados_y_de_meses_anteriores_no_suman_en_cobrado_ni_ingresos(): void
    {
        $admin = User::factory()->create(['rol' => Rol::Admin]);

        $proforma = Proforma::factory()->aceptada()->create();
        $orden = OrdenServicio::factory()->paraProforma($proforma)->finalizada()->create();

        $cuenta = CuentaPorCobrar::factory()->create([
            'orden_id' => $orden->id,
            'cliente_id' => $proforma->cliente_id,
            'fecha_vencimiento' => today()->addDays(5)->toDateString(),
            'monto_total' => 1000,
            'saldo' => 500,
            'estado_cobro' => EstadoCobro::Parcial,
        ]);

        PagoCuenta::factory()->create([
            'cuenta_id' => $cuenta->id,
            'monto' => 200,
            'fecha_pago' => today()->toDateString(),
            'created_by' => $admin->id,
        ]);

        PagoCuenta::factory()->anulado()->create([
            'cuenta_id' => $cuenta->id,
            'monto' => 300,
            'fecha_pago' => today()->toDateString(),
            'created_by' => $admin->id,
        ]);

        PagoCuenta::factory()->create([
            'cuenta_id' => $cuenta->id,
            'monto' => 50,
            'fecha_pago' => today()->subMonthNoOverflow()->startOfMonth()->toDateString(),
            'created_by' => $admin->id,
        ]);

        $this->actingAs($admin)->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonPath('cobros.cuentas', 1)
            ->assertJsonPath('cobros.pendientes', 1)
            ->assertJsonPath('cobros.vencidas', 0)
            ->assertJsonPath('cobros.saldo', 500)
            ->assertJsonPath('cobros.monto_vencido', 0)
            ->assertJsonPath('cobros.cobrado_mes', 200)
            ->assertJsonPath('cobros.monto_total', 1000)
            ->assertJsonPath('cobros.aging.al_dia', 500)
            ->assertJsonPath('cobros.aging.d1_30', 0)
            ->assertJsonPath('cobros.aging.d31_60', 0)
            ->assertJsonPath('cobros.aging.d61_90', 0)
            ->assertJsonPath('cobros.aging.d90_plus', 0)
            ->assertJsonPath('ingresos_mes', 200)
            ->assertJsonPath('saldo_por_cobrar', 500);
    }

    public function test_orden_cancelada_no_suma_en_servicios_mas_solicitados(): void
    {
        $admin = User::factory()->create(['rol' => Rol::Admin]);
        $cliente = Cliente::factory()->create();
        $servicio = Servicio::factory()->create([
            'nombre' => 'Instalación básica',
            'precio_base' => 100,
        ]);

        $proforma = Proforma::factory()->aceptada()->create([
            'cliente_id' => $cliente->id,
            'total' => 1100,
        ]);

        $activa = OrdenServicio::factory()->paraProforma($proforma)->create([
            'estado' => EstadoOrden::Pendiente,
        ]);

        $cancelada = OrdenServicio::factory()->paraProforma($proforma)->cancelada()->create();

        $this->crearItemServicio($activa, $servicio, 2, 200);
        $this->crearItemServicio($cancelada, $servicio, 9, 900);

        $this->actingAs($admin)->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonCount(1, 'servicios_mas_solicitados')
            ->assertJsonPath('servicios_mas_solicitados.0.servicio_id', $servicio->id)
            ->assertJsonPath('servicios_mas_solicitados.0.cantidad', 2)
            ->assertJsonPath('servicios_mas_solicitados.0.total', 200)
            ->assertJsonPath('ordenes.total', 2)
            ->assertJsonPath('ordenes.activas', 1)
            ->assertJsonPath('ordenes.canceladas', 1);
    }

    public function test_cliente_no_accede_al_dashboard(): void
    {
        $cliente = User::factory()->create(['rol' => Rol::Cliente]);

        $this->actingAs($cliente)->getJson('/api/v1/dashboard')->assertForbidden();
    }

    private function crearItemServicio(
        OrdenServicio $orden,
        Servicio $servicio,
        float $cantidad,
        float $total,
    ): void {
        $orden->items()->create([
            'tipo' => TipoProformaItem::Servicio->value,
            'servicio_id' => $servicio->id,
            'descripcion' => $servicio->nombre,
            'unidad_medida' => 'servicio',
            'cantidad' => $cantidad,
            'precio_unitario' => $total / $cantidad,
            'total_linea' => $total,
        ]);
    }
}
