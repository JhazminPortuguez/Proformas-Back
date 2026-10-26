<?php

namespace Tests\Feature;

use App\Enums\EstadoOrden;
use App\Models\Cliente;
use App\Models\CuentaPorCobrar;
use App\Models\Empresa;
use App\Models\OrdenItem;
use App\Models\OrdenServicio;
use App\Models\PagoCuenta;
use App\Models\Proforma;
use App\Models\Servicio;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClienteHistorialTest extends TestCase
{
    use RefreshDatabase;

    public function test_resumen_agrega_proformas_ordenes_cobros_y_servicios(): void
    {
        $admin = User::factory()->admin()->create();
        $cliente = Cliente::factory()->create();

        Proforma::factory()->create(['cliente_id' => $cliente->id, 'total' => 100]);
        Proforma::factory()->enviada()->create(['cliente_id' => $cliente->id, 'total' => 200]);
        $aceptada = Proforma::factory()->aceptada()->create(['cliente_id' => $cliente->id, 'total' => 230]);

        $pendiente = OrdenServicio::factory()->paraProforma($aceptada)->create([
            'estado' => EstadoOrden::Pendiente,
        ]);
        OrdenServicio::factory()->asignada()->paraProforma($aceptada)->create();
        OrdenServicio::factory()->enProceso()->paraProforma($aceptada)->create();
        $finalizada = OrdenServicio::factory()->finalizada()->paraProforma($aceptada)->create();
        $finalizadaVencida = OrdenServicio::factory()->finalizada()->paraProforma($aceptada)->create();

        $servicio = Servicio::factory()->create(['nombre' => 'Instalación básica']);

        OrdenItem::factory()->servicio()->create([
            'orden_id' => $pendiente->id,
            'servicio_id' => $servicio->id,
            'descripcion' => 'Instalación básica',
            'cantidad' => 1,
            'precio_unitario' => 700,
            'total_linea' => 700,
        ]);
        OrdenItem::factory()->servicio()->create([
            'orden_id' => $finalizada->id,
            'servicio_id' => $servicio->id,
            'descripcion' => 'Instalación básica',
            'cantidad' => 1,
            'precio_unitario' => 350,
            'total_linea' => 350,
        ]);
        OrdenItem::factory()->create([
            'orden_id' => $finalizada->id,
            'cantidad' => 2,
            'precio_unitario' => 50,
            'total_linea' => 100,
        ]);

        $cancelada = OrdenServicio::factory()->cancelada()->paraProforma($aceptada)->create();
        OrdenItem::factory()->servicio()->create([
            'orden_id' => $cancelada->id,
            'descripcion' => 'Servicio cancelado',
            'cantidad' => 10,
            'precio_unitario' => 500,
            'total_linea' => 5000,
        ]);

        $cuentaParcial = CuentaPorCobrar::factory()->create([
            'orden_id' => $finalizada->id,
            'cliente_id' => $cliente->id,
            'monto_total' => 530,
            'saldo' => 265,
            'fecha_vencimiento' => today()->addDays(15)->toDateString(),
        ]);
        $cuentaVencida = CuentaPorCobrar::factory()->vencida()->create([
            'orden_id' => $finalizadaVencida->id,
            'cliente_id' => $cliente->id,
            'monto_total' => 1000,
            'saldo' => 1000,
        ]);

        PagoCuenta::factory()->create([
            'cuenta_id' => $cuentaParcial->id,
            'monto' => 265,
            'created_by' => $admin->id,
        ]);
        PagoCuenta::factory()->anulado()->create([
            'cuenta_id' => $cuentaParcial->id,
            'monto' => 100,
            'created_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->getJson("/api/v1/clientes/{$cliente->id}/resumen")
            ->assertOk()
            ->assertJsonPath('cliente.id', $cliente->id)
            ->assertJsonPath('cliente.proformas_count', 3)
            ->assertJsonPath('cliente.ordenes_count', 6)
            ->assertJsonPath('proformas.total', 3)
            ->assertJsonPath('proformas.por_estado', [
                'BORRADOR' => 1,
                'ENVIADA' => 1,
                'ACEPTADA' => 1,
                'RECHAZADA' => 0,
            ])
            ->assertJsonPath('proformas.monto_total', 530)
            ->assertJsonPath('proformas.monto_aceptado', 230)
            ->assertJsonPath('ordenes', [
                'total' => 6,
                'activas' => 3,
                'pendientes' => 1,
                'asignadas' => 1,
                'en_proceso' => 1,
                'finalizadas' => 2,
                'canceladas' => 1,
            ])
            ->assertJsonPath('cobros', [
                'cuentas' => 2,
                'saldo' => 1265,
                'vencidas' => 1,
                'monto_total' => 1530,
                'total_pagado' => 265,
            ])
            ->assertJsonPath('servicios', [
                'realizados' => 2,
                'monto' => 1050,
            ]);

        $this->assertSame(1000, (int) $cuentaVencida->saldo);
    }

    public function test_resumen_de_cliente_sin_historial_devuelve_ceros_y_claves_completas(): void
    {
        $admin = User::factory()->admin()->create();
        $cliente = Cliente::factory()->create();

        $this->actingAs($admin)
            ->getJson("/api/v1/clientes/{$cliente->id}/resumen")
            ->assertOk()
            ->assertJsonPath('cliente.proformas_count', 0)
            ->assertJsonPath('cliente.ordenes_count', 0)
            ->assertJsonPath('proformas', [
                'total' => 0,
                'por_estado' => [
                    'BORRADOR' => 0,
                    'ENVIADA' => 0,
                    'ACEPTADA' => 0,
                    'RECHAZADA' => 0,
                ],
                'monto_total' => 0,
                'monto_aceptado' => 0,
            ])
            ->assertJsonPath('ordenes', [
                'total' => 0,
                'activas' => 0,
                'pendientes' => 0,
                'asignadas' => 0,
                'en_proceso' => 0,
                'finalizadas' => 0,
                'canceladas' => 0,
            ])
            ->assertJsonPath('cobros', [
                'cuentas' => 0,
                'saldo' => 0,
                'vencidas' => 0,
                'monto_total' => 0,
                'total_pagado' => 0,
            ])
            ->assertJsonPath('servicios', [
                'realizados' => 0,
                'monto' => 0,
            ]);
    }

    public function test_servicios_pagina_y_ordena_excluyendo_ordenes_canceladas(): void
    {
        $admin = User::factory()->admin()->create();
        $operario = User::factory()->operario()->create(['name' => 'Operario Demo']);
        $cliente = Cliente::factory()->create();
        $proforma = Proforma::factory()->aceptada()->create(['cliente_id' => $cliente->id]);

        $ordenAntigua = OrdenServicio::factory()->finalizada()->paraProforma($proforma)->create([
            'operario_id' => $operario->id,
            'fecha_fin' => '2026-10-20 15:00:00',
            'created_at' => '2026-10-18 09:00:00',
        ]);
        $ordenReciente = OrdenServicio::factory()->paraProforma($proforma)->create([
            'estado' => EstadoOrden::Pendiente,
            'fecha_fin' => null,
            'created_at' => '2026-10-22 09:00:00',
        ]);
        $ordenCancelada = OrdenServicio::factory()->cancelada()->paraProforma($proforma)->create([
            'created_at' => '2026-10-24 09:00:00',
        ]);

        $instalacion = Servicio::factory()->create(['nombre' => 'Instalación básica']);
        $mantenimiento = Servicio::factory()->create(['nombre' => 'Mantenimiento']);

        $itemAntiguo = OrdenItem::factory()->servicio()->create([
            'orden_id' => $ordenAntigua->id,
            'servicio_id' => $instalacion->id,
            'descripcion' => 'Instalación básica',
            'cantidad' => 2,
            'precio_unitario' => 350,
            'total_linea' => 700,
        ]);
        $itemReciente = OrdenItem::factory()->servicio()->create([
            'orden_id' => $ordenReciente->id,
            'servicio_id' => $mantenimiento->id,
            'descripcion' => 'Mantenimiento',
            'cantidad' => 1,
            'precio_unitario' => 350,
            'total_linea' => 350,
        ]);
        OrdenItem::factory()->servicio()->create([
            'orden_id' => $ordenCancelada->id,
            'descripcion' => 'Servicio cancelado',
            'cantidad' => 9,
            'total_linea' => 900,
        ]);
        OrdenItem::factory()->create([
            'orden_id' => $ordenReciente->id,
            'cantidad' => 1,
            'total_linea' => 100,
        ]);

        $primera = $this->actingAs($admin)
            ->getJson("/api/v1/clientes/{$cliente->id}/servicios?per_page=1&page=1")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('data.0.id', $itemReciente->id)
            ->assertJsonPath('data.0.orden_id', $ordenReciente->id)
            ->assertJsonPath('data.0.orden_numero', $ordenReciente->numero)
            ->assertJsonPath('data.0.orden_estado', 'PENDIENTE')
            ->assertJsonPath('data.0.fecha', '2026-10-22')
            ->assertJsonPath('data.0.servicio_id', $mantenimiento->id)
            ->assertJsonPath('data.0.servicio_nombre', 'Mantenimiento')
            ->assertJsonPath('data.0.descripcion', 'Mantenimiento')
            ->assertJsonPath('data.0.cantidad', 1)
            ->assertJsonPath('data.0.total_linea', 350)
            ->assertJsonPath('data.0.operario_nombre', null);

        $this->assertSame($itemReciente->id, $primera->json('data.0.id'));

        $this->actingAs($admin)
            ->getJson("/api/v1/clientes/{$cliente->id}/servicios?per_page=1&page=2")
            ->assertOk()
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('data.0.id', $itemAntiguo->id)
            ->assertJsonPath('data.0.orden_estado', 'FINALIZADA')
            ->assertJsonPath('data.0.fecha', '2026-10-20')
            ->assertJsonPath('data.0.servicio_nombre', 'Instalación básica')
            ->assertJsonPath('data.0.cantidad', 2)
            ->assertJsonPath('data.0.total_linea', 700)
            ->assertJsonPath('data.0.operario_nombre', 'Operario Demo');
    }

    public function test_operario_puede_ver_la_ficha_y_el_cliente_no(): void
    {
        $operario = User::factory()->operario()->create();
        $cliente = Cliente::factory()->create();

        $this->actingAs($operario)
            ->getJson("/api/v1/clientes/{$cliente->id}/resumen")
            ->assertOk();

        $this->actingAs($operario)
            ->getJson("/api/v1/clientes/{$cliente->id}/servicios")
            ->assertOk();

        $usuarioCliente = User::factory()->cliente()->create();

        $this->actingAs($usuarioCliente)
            ->getJson("/api/v1/clientes/{$cliente->id}/resumen")
            ->assertForbidden();

        $this->actingAs($usuarioCliente)
            ->getJson("/api/v1/clientes/{$cliente->id}/servicios")
            ->assertForbidden();
    }

    public function test_cliente_de_otra_empresa_devuelve_404(): void
    {
        $empresa = Empresa::factory()->create();
        $adminAjeno = User::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $cliente = Cliente::factory()->create();

        $this->actingAs($adminAjeno)
            ->getJson("/api/v1/clientes/{$cliente->id}/resumen")
            ->assertNotFound();

        $this->actingAs($adminAjeno)
            ->getJson("/api/v1/clientes/{$cliente->id}/servicios")
            ->assertNotFound();
    }

    public function test_index_y_show_incluyen_conteos_de_proformas_y_ordenes(): void
    {
        $admin = User::factory()->admin()->create();
        $cliente = Cliente::factory()->create();

        Proforma::factory()->create(['cliente_id' => $cliente->id]);
        $proforma = Proforma::factory()->aceptada()->create(['cliente_id' => $cliente->id]);

        OrdenServicio::factory()->paraProforma($proforma)->create();
        OrdenServicio::factory()->asignada()->paraProforma($proforma)->create();
        OrdenServicio::factory()->finalizada()->paraProforma($proforma)->create();

        $this->actingAs($admin)
            ->getJson('/api/v1/clientes')
            ->assertOk()
            ->assertJsonPath('data.0.id', $cliente->id)
            ->assertJsonPath('data.0.proformas_count', 2)
            ->assertJsonPath('data.0.ordenes_count', 3);

        $this->actingAs($admin)
            ->getJson("/api/v1/clientes/{$cliente->id}")
            ->assertOk()
            ->assertJsonPath('proformas_count', 2)
            ->assertJsonPath('ordenes_count', 3);
    }
}
