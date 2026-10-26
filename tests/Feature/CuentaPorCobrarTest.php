<?php

namespace Tests\Feature;

use App\Actions\CuentasPorCobrar\GenerarCuentaPorCobrar;
use App\Enums\EstadoCobro;
use App\Enums\Rol;
use App\Models\Cliente;
use App\Models\CuentaPorCobrar;
use App\Models\OrdenServicio;
use App\Models\PagoCuenta;
use App\Models\Producto;
use App\Models\Proforma;
use App\Models\Servicio;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class CuentaPorCobrarTest extends TestCase
{
    use RefreshDatabase;

    public function test_cerrar_orden_genera_una_sola_cuenta_con_total_vencimiento_y_secuencia(): void
    {
        $admin = $this->admin();
        [$ordenId, $cierre] = $this->cerrarOrden($admin);

        $cierre->assertOk()
            ->assertJsonPath('estado', 'FINALIZADA')
            ->assertJsonPath('cuenta_por_cobrar_id', fn ($id) => $id !== null)
            ->assertJsonPath('cuenta.estado_cobro', 'PENDIENTE')
            ->assertJsonPath('cuenta.saldo', 350)
            ->assertJsonPath('cuenta.numero', sprintf('CXC-%d-000001', now()->year));

        $cuenta = CuentaPorCobrar::query()->where('orden_id', $ordenId)->firstOrFail();

        $this->assertSame(1, CuentaPorCobrar::query()->count());
        $this->assertSame('350.00', $cuenta->monto_total);
        $this->assertSame('350.00', $cuenta->saldo);
        $this->assertSame(EstadoCobro::Pendiente, $cuenta->estado_cobro);
        $this->assertSame($cierre->json('cuenta.id'), $cuenta->id);
        $this->assertSame(
            $cuenta->fecha_emision->copy()->addDays(30)->toDateString(),
            $cuenta->fecha_vencimiento->toDateString(),
        );

        $this->assertDatabaseHas('cuenta_sequences', [
            'empresa_id' => 1,
            'anio' => now()->year,
            'ultimo_numero' => 1,
        ]);

        // Generar de nuevo no duplica ni consume otro correlativo.
        $orden = OrdenServicio::query()->findOrFail($ordenId);
        $repetida = app(GenerarCuentaPorCobrar::class)->ejecutar($orden, $admin);

        $this->assertSame($cuenta->id, $repetida->id);
        $this->assertSame(1, CuentaPorCobrar::query()->count());
        $this->assertDatabaseHas('cuenta_sequences', [
            'empresa_id' => 1,
            'anio' => now()->year,
            'ultimo_numero' => 1,
        ]);
    }

    public function test_no_genera_cuenta_al_finalizar_ni_al_cancelar(): void
    {
        $admin = $this->admin();
        $operario = $this->operario();
        $proforma = $this->proformaAceptada($admin);
        $ordenId = $this->crearOrden($admin, $proforma, ['operario_id' => $operario->id])
            ->assertCreated()
            ->json('id');

        $this->actingAs($admin)->postJson("/api/v1/ordenes/{$ordenId}/iniciar")->assertOk();
        $this->actingAs($admin)->postJson("/api/v1/ordenes/{$ordenId}/finalizar")->assertOk();

        $this->assertSame(0, CuentaPorCobrar::query()->count());

        $proformaCancelada = $this->proformaAceptada($admin);
        $ordenCancelada = $this->crearOrden($admin, $proformaCancelada)->assertCreated()->json('id');

        $this->actingAs($admin)->postJson("/api/v1/ordenes/{$ordenCancelada}/cancelar", [
            'motivo' => 'El cliente desistió del servicio.',
        ])->assertOk();

        $this->assertSame(0, CuentaPorCobrar::query()->count());
    }

    public function test_cuenta_de_orden_sin_lineas_nace_pagada_con_saldo_cero(): void
    {
        $admin = $this->admin();
        [$ordenId, $cierre] = $this->cerrarOrden($admin, items: []);

        $cierre->assertOk()
            ->assertJsonPath('cuenta.saldo', 0)
            ->assertJsonPath('cuenta.estado_cobro', 'PAGADA');

        $this->assertDatabaseHas('cuentas_por_cobrar', [
            'orden_id' => $ordenId,
            'monto_total' => 0,
            'saldo' => 0,
            'estado_cobro' => 'PAGADA',
        ]);
    }

    public function test_pago_parcial_y_segundo_pago_que_completa_la_cuenta(): void
    {
        $admin = $this->admin();
        [$ordenId, $cierre] = $this->cerrarOrden($admin);

        $cuentaId = $cierre->json('cuenta.id');

        $parcial = $this->actingAs($admin)->postJson("/api/v1/cuentas-por-cobrar/{$cuentaId}/pagos", [
            'monto' => 100,
            'metodo' => 'EFECTIVO',
            'referencia' => 'REC-001',
            'observaciones' => 'Primer abono.',
        ]);

        $parcial->assertCreated()
            ->assertJsonPath('saldo', 250)
            ->assertJsonPath('total_pagado', 100)
            ->assertJsonPath('estado_cobro', 'PARCIAL')
            ->assertJsonPath('pagos_count', 1)
            ->assertJsonPath('acciones_disponibles', ['ver', 'pagar'])
            ->assertJsonPath('pagos.0.monto', 100)
            ->assertJsonPath('pagos.0.metodo', 'EFECTIVO')
            ->assertJsonPath('pagos.0.referencia', 'REC-001')
            ->assertJsonPath('pagos.0.usuario.id', $admin->id)
            ->assertJsonPath('pagos.0.anulado', false)
            ->assertJsonPath('pagos.0.puede_anular', true);

        $this->assertSame($parcial->json('pagos.0.fecha_pago'), today()->toDateString());

        $completo = $this->actingAs($admin)->postJson("/api/v1/cuentas-por-cobrar/{$cuentaId}/pagos", [
            'monto' => 250,
            'metodo' => 'TRANSFERENCIA',
        ]);

        $completo->assertCreated()
            ->assertJsonPath('saldo', 0)
            ->assertJsonPath('total_pagado', 350)
            ->assertJsonPath('estado_cobro', 'PAGADA')
            ->assertJsonPath('pagos_count', 2)
            ->assertJsonPath('acciones_disponibles', ['ver']);

        $this->assertDatabaseHas('cuentas_por_cobrar', [
            'id' => $cuentaId,
            'saldo' => 0,
            'estado_cobro' => 'PAGADA',
            'updated_by' => $admin->id,
        ]);
    }

    public function test_rechaza_sobrepago_montos_invalidos_metodo_invalido_y_pago_en_cuenta_pagada(): void
    {
        $admin = $this->admin();
        [, $cierre] = $this->cerrarOrden($admin);
        $cuentaId = $cierre->json('cuenta.id');

        $this->actingAs($admin)->postJson("/api/v1/cuentas-por-cobrar/{$cuentaId}/pagos", [
            'monto' => 350.01,
            'metodo' => 'EFECTIVO',
        ])->assertStatus(422)->assertJsonValidationErrors('monto');

        foreach ([0, -10] as $monto) {
            $this->actingAs($admin)->postJson("/api/v1/cuentas-por-cobrar/{$cuentaId}/pagos", [
                'monto' => $monto,
                'metodo' => 'EFECTIVO',
            ])->assertStatus(422)->assertJsonValidationErrors('monto');
        }

        $this->actingAs($admin)->postJson("/api/v1/cuentas-por-cobrar/{$cuentaId}/pagos", [
            'monto' => 100,
            'metodo' => 'BITCOIN',
        ])->assertStatus(422)->assertJsonValidationErrors('metodo');

        $this->actingAs($admin)->postJson("/api/v1/cuentas-por-cobrar/{$cuentaId}/pagos", [
            'monto' => 350,
            'metodo' => 'QR',
        ])->assertCreated();

        $this->actingAs($admin)->postJson("/api/v1/cuentas-por-cobrar/{$cuentaId}/pagos", [
            'monto' => 1,
            'metodo' => 'EFECTIVO',
        ])->assertStatus(422)->assertJsonValidationErrors('monto');

        $this->assertSame(1, PagoCuenta::query()->where('cuenta_id', $cuentaId)->count());
    }

    public function test_operario_registra_pagos_pero_solo_admin_anula_con_auditoria(): void
    {
        $admin = $this->admin();
        $operario = $this->operario();
        [, $cierre] = $this->cerrarOrden($admin);
        $cuentaId = $cierre->json('cuenta.id');

        $pago = $this->actingAs($operario)->postJson("/api/v1/cuentas-por-cobrar/{$cuentaId}/pagos", [
            'monto' => 120,
            'metodo' => 'TARJETA',
        ])->assertCreated()->assertJsonPath('pagos.0.usuario.id', $operario->id);

        $pagoId = $pago->json('pagos.0.id');

        $this->actingAs($operario)->postJson("/api/v1/pagos-cuenta/{$pagoId}/anular", [
            'motivo' => 'Intento no permitido.',
        ])->assertForbidden();

        $this->assertDatabaseHas('pagos_cuenta', [
            'id' => $pagoId,
            'anulado_at' => null,
        ]);

        $this->actingAs($admin)->postJson("/api/v1/pagos-cuenta/{$pagoId}/anular", [
            'motivo' => 'Pago registrado con monto incorrecto.',
        ])
            ->assertOk()
            ->assertJsonPath('saldo', 350)
            ->assertJsonPath('estado_cobro', 'PENDIENTE')
            ->assertJsonPath('total_pagado', 0)
            ->assertJsonPath('pagos.0.anulado', true)
            ->assertJsonPath('pagos.0.anulado_por', $admin->id)
            ->assertJsonPath('pagos.0.motivo_anulacion', 'Pago registrado con monto incorrecto.')
            ->assertJsonPath('pagos.0.puede_anular', false)
            ->assertJsonPath('pagos.0.acciones_disponibles', []);

        $this->assertDatabaseHas('pagos_cuenta', [
            'id' => $pagoId,
            'anulado_por' => $admin->id,
            'motivo_anulacion' => 'Pago registrado con monto incorrecto.',
        ]);

        $this->actingAs($admin)->postJson("/api/v1/pagos-cuenta/{$pagoId}/anular", [
            'motivo' => 'Segundo intento de anulación.',
        ])->assertStatus(422)->assertJsonValidationErrors('pago');
    }

    public function test_anular_pago_parcial_recalcula_estado_parcial(): void
    {
        $admin = $this->admin();
        [, $cierre] = $this->cerrarOrden($admin);
        $cuentaId = $cierre->json('cuenta.id');

        $primero = $this->actingAs($admin)->postJson("/api/v1/cuentas-por-cobrar/{$cuentaId}/pagos", [
            'monto' => 200,
            'metodo' => 'EFECTIVO',
        ])->assertCreated();

        $this->actingAs($admin)->postJson("/api/v1/cuentas-por-cobrar/{$cuentaId}/pagos", [
            'monto' => 50,
            'metodo' => 'QR',
        ])->assertCreated();

        $this->actingAs($admin)->postJson('/api/v1/pagos-cuenta/'.$primero->json('pagos.0.id').'/anular', [
            'motivo' => 'Se revirtió el abono.',
        ])
            ->assertOk()
            ->assertJsonPath('saldo', 300)
            ->assertJsonPath('estado_cobro', 'PARCIAL');
    }

    public function test_listado_filtros_busqueda_con_ene_vencida_y_paginacion(): void
    {
        $admin = $this->admin();
        $operario = $this->operario();

        $clienteNandu = Cliente::factory()->create(['nombre_razon' => 'Taller Ñandú']);
        $orden = OrdenServicio::factory()->finalizada()->create([
            'numero' => sprintf('OS-%d-900001', now()->year),
            'cliente_id' => $clienteNandu->id,
        ]);

        $vencida = CuentaPorCobrar::factory()->vencida()->create([
            'orden_id' => $orden->id,
            'cliente_id' => $clienteNandu->id,
            'monto_total' => 1000,
            'saldo' => 800,
            'estado_cobro' => EstadoCobro::Parcial,
        ]);

        CuentaPorCobrar::factory()->create([
            'monto_total' => 500,
            'saldo' => 200,
            'estado_cobro' => EstadoCobro::Parcial,
        ]);

        CuentaPorCobrar::factory()->pagada()->create([
            'monto_total' => 300,
            'saldo' => 0,
        ]);

        $this->actingAs($admin)
            ->getJson('/api/v1/cuentas-por-cobrar?search=nandu')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $vencida->id);

        $this->actingAs($admin)
            ->getJson('/api/v1/cuentas-por-cobrar?search='.urlencode($vencida->numero))
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $vencida->id);

        $this->actingAs($admin)
            ->getJson('/api/v1/cuentas-por-cobrar?search='.urlencode($orden->numero))
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $vencida->id);

        $this->actingAs($admin)
            ->getJson('/api/v1/cuentas-por-cobrar?estado_cobro=PARCIAL')
            ->assertOk()
            ->assertJsonPath('meta.total', 2);

        $this->actingAs($admin)
            ->getJson('/api/v1/cuentas-por-cobrar?vencida=1')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $vencida->id)
            ->assertJsonPath('data.0.vencida', true)
            ->assertJsonPath('data.0.dias_vencida', 30);

        $this->actingAs($admin)
            ->getJson('/api/v1/cuentas-por-cobrar?cliente_id='.$clienteNandu->id)
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $vencida->id);

        $this->actingAs($admin)
            ->getJson('/api/v1/cuentas-por-cobrar?desde='.today()->toDateString().'&hasta='.today()->toDateString())
            ->assertOk()
            ->assertJsonPath('meta.total', 2);

        $this->actingAs($admin)
            ->getJson('/api/v1/cuentas-por-cobrar?per_page=1')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.last_page', 3);

        $this->actingAs($operario)->getJson('/api/v1/cuentas-por-cobrar')->assertOk();
        $this->actingAs($operario)->getJson("/api/v1/cuentas-por-cobrar/{$vencida->id}")->assertOk();
    }

    public function test_detalle_incluye_pagos_anulados_usuario_anulador_y_orden(): void
    {
        $admin = $this->admin();
        [, $cierre] = $this->cerrarOrden($admin);
        $cuentaId = $cierre->json('cuenta.id');

        $pagoId = $this->actingAs($admin)->postJson("/api/v1/cuentas-por-cobrar/{$cuentaId}/pagos", [
            'monto' => 75,
            'metodo' => 'OTRO',
        ])->assertCreated()->json('pagos.0.id');

        $this->actingAs($admin)->postJson("/api/v1/pagos-cuenta/{$pagoId}/anular", [
            'motivo' => 'Cobro duplicado.',
        ])->assertOk();

        $detalle = $this->actingAs($admin)->getJson("/api/v1/cuentas-por-cobrar/{$cuentaId}")
            ->assertOk()
            ->assertJsonPath('cliente.id', fn ($id) => $id !== null)
            ->assertJsonPath('orden.id', fn ($id) => $id !== null)
            ->assertJsonPath('orden.estado', 'FINALIZADA')
            ->assertJsonPath('pagos_count', 1)
            ->assertJsonPath('pagos.0.anulado', true)
            ->assertJsonPath('pagos.0.usuario.id', $admin->id)
            ->assertJsonPath('pagos.0.anulador.id', $admin->id)
            ->assertJsonPath('pagos.0.motivo_anulacion', 'Cobro duplicado.')
            ->assertJsonPath('acciones_disponibles', ['ver', 'pagar']);

        $this->assertSame($pagoId, $detalle->json('pagos.0.id'));
    }

    public function test_resumen_calcula_cuentas_pendientes_vencidas_saldos_y_cobro_del_mes(): void
    {
        $admin = $this->admin();

        CuentaPorCobrar::factory()->vencida()->create([
            'monto_total' => 1000,
            'saldo' => 1000,
            'estado_cobro' => EstadoCobro::Pendiente,
        ]);

        $parcial = CuentaPorCobrar::factory()->create([
            'monto_total' => 500,
            'saldo' => 200,
            'estado_cobro' => EstadoCobro::Parcial,
        ]);

        CuentaPorCobrar::factory()->pagada()->create([
            'monto_total' => 300,
            'saldo' => 0,
        ]);

        PagoCuenta::factory()->create([
            'cuenta_id' => $parcial->id,
            'monto' => 100,
            'fecha_pago' => today()->toDateString(),
            'created_by' => $admin->id,
        ]);

        PagoCuenta::factory()->anulado()->create([
            'cuenta_id' => $parcial->id,
            'monto' => 50,
            'fecha_pago' => today()->toDateString(),
            'created_by' => $admin->id,
        ]);

        $this->actingAs($admin)->getJson('/api/v1/cuentas-por-cobrar/resumen')
            ->assertOk()
            ->assertJsonPath('cuentas', 3)
            ->assertJsonPath('pendientes', 2)
            ->assertJsonPath('vencidas', 1)
            ->assertJsonPath('saldo', 1200)
            ->assertJsonPath('cobrado_mes', 100)
            ->assertJsonPath('monto_total', 1800);
    }

    public function test_cliente_no_accede_al_modulo_de_cuentas_por_cobrar(): void
    {
        $cliente = User::factory()->create(['rol' => Rol::Cliente]);
        $cuenta = CuentaPorCobrar::factory()->create();

        $this->actingAs($cliente)->getJson('/api/v1/cuentas-por-cobrar')->assertForbidden();
        $this->actingAs($cliente)->getJson("/api/v1/cuentas-por-cobrar/{$cuenta->id}")->assertForbidden();
        $this->actingAs($cliente)->getJson('/api/v1/cuentas-por-cobrar/resumen')->assertForbidden();
        $this->actingAs($cliente)->postJson("/api/v1/cuentas-por-cobrar/{$cuenta->id}/pagos", [
            'monto' => 10,
            'metodo' => 'EFECTIVO',
        ])->assertForbidden();
    }

    public function test_orden_expone_smart_button_de_cuenta_cuando_esta_cargada(): void
    {
        $admin = $this->admin();
        [$ordenId, $cierre] = $this->cerrarOrden($admin);
        $cuentaId = $cierre->json('cuenta.id');

        $this->actingAs($admin)->getJson("/api/v1/ordenes/{$ordenId}")
            ->assertOk()
            ->assertJsonPath('cuenta_por_cobrar_id', $cuentaId)
            ->assertJsonPath('cuenta.id', $cuentaId)
            ->assertJsonPath('cuenta.estado_cobro', 'PENDIENTE')
            ->assertJsonPath('cuenta.saldo', 350);

        $this->actingAs($admin)->getJson('/api/v1/ordenes')
            ->assertOk()
            ->assertJsonPath('data.0.cuenta_por_cobrar_id', $cuentaId);
    }

    public function test_postgres_rechaza_estado_de_cobro_invalido(): void
    {
        $this->soloPostgres();

        $cuenta = CuentaPorCobrar::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('cuentas_por_cobrar')
            ->where('id', $cuenta->id)
            ->update(['estado_cobro' => 'INVALIDO']);
    }

    public function test_postgres_rechaza_saldo_mayor_al_monto_total(): void
    {
        $this->soloPostgres();

        $cuenta = CuentaPorCobrar::factory()->create(['monto_total' => 1000, 'saldo' => 1000]);

        $this->expectException(QueryException::class);

        DB::table('cuentas_por_cobrar')
            ->where('id', $cuenta->id)
            ->update(['saldo' => 1000.01]);
    }

    public function test_postgres_rechaza_fecha_de_vencimiento_anterior_a_la_emision(): void
    {
        $this->soloPostgres();

        $cuenta = CuentaPorCobrar::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('cuentas_por_cobrar')
            ->where('id', $cuenta->id)
            ->update(['fecha_vencimiento' => $cuenta->fecha_emision->copy()->subDay()->toDateString()]);
    }

    public function test_postgres_rechaza_pago_con_monto_cero(): void
    {
        $this->soloPostgres();

        $cuenta = CuentaPorCobrar::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('pagos_cuenta')->insert([
            'empresa_id' => $cuenta->empresa_id,
            'cuenta_id' => $cuenta->id,
            'monto' => 0,
            'metodo' => 'EFECTIVO',
            'fecha_pago' => today()->toDateString(),
            'created_by' => $this->admin()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_postgres_rechaza_metodo_de_pago_invalido(): void
    {
        $this->soloPostgres();

        $cuenta = CuentaPorCobrar::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('pagos_cuenta')->insert([
            'empresa_id' => $cuenta->empresa_id,
            'cuenta_id' => $cuenta->id,
            'monto' => 10,
            'metodo' => 'BITCOIN',
            'fecha_pago' => today()->toDateString(),
            'created_by' => $this->admin()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_postgres_registrar_pago_bloquea_la_cuenta_con_for_update(): void
    {
        $this->soloPostgres();

        $admin = $this->admin();
        [, $cierre] = $this->cerrarOrden($admin);

        DB::connection()->flushQueryLog();
        DB::connection()->enableQueryLog();

        $this->actingAs($admin)->postJson('/api/v1/cuentas-por-cobrar/'.$cierre->json('cuenta.id').'/pagos', [
            'monto' => 10,
            'metodo' => 'EFECTIVO',
        ])->assertCreated();

        $bloqueos = collect(DB::connection()->getQueryLog())
            ->pluck('query')
            ->filter(fn (string $sql): bool => str_contains(strtolower($sql), 'for update'));

        $this->assertTrue(
            $bloqueos->contains(fn (string $sql): bool => str_contains($sql, 'cuentas_por_cobrar')),
            'El registro de pagos debe bloquear la cuenta con for update.',
        );
    }

    private function admin(): User
    {
        return User::factory()->create(['rol' => Rol::Admin]);
    }

    private function operario(): User
    {
        return User::factory()->create(['rol' => Rol::Operario, 'activo' => true]);
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $items
     * @return array{0: int, 1: TestResponse}
     */
    private function cerrarOrden(User $admin, ?Cliente $cliente = null, ?array $items = null): array
    {
        $proforma = $this->proformaAceptada($admin, $cliente, $items);
        $operario = $this->operario();
        $ordenId = $this->crearOrden($admin, $proforma, ['operario_id' => $operario->id])
            ->assertCreated()
            ->json('id');

        $this->actingAs($admin)->postJson("/api/v1/ordenes/{$ordenId}/iniciar")->assertOk();
        $this->actingAs($admin)->postJson("/api/v1/ordenes/{$ordenId}/finalizar")->assertOk();

        $cierre = $this->actingAs($admin)->postJson("/api/v1/ordenes/{$ordenId}/cerrar")->assertOk();

        return [$ordenId, $cierre];
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $items
     */
    private function proformaAceptada(User $admin, ?Cliente $cliente = null, ?array $items = null): Proforma
    {
        $cliente ??= Cliente::factory()->create();
        $servicio = Servicio::factory()->create(['precio_base' => 100]);
        $producto = Producto::factory()->create(['precio_unitario' => 50]);

        $proforma = Proforma::factory()->aceptada()->create([
            'cliente_id' => $cliente->id,
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
            'fecha_emision' => today()->toDateString(),
        ]);

        $items ??= [
            [
                'tipo' => 'SERVICIO',
                'servicio_id' => $servicio->id,
                'producto_id' => null,
                'nombre' => $servicio->nombre,
                'descripcion' => 'Servicio de prueba',
                'unidad_medida' => 'servicio',
                'cantidad' => 2,
                'precio_unitario' => 100,
                'total_linea' => 200,
            ],
            [
                'tipo' => 'MATERIAL',
                'servicio_id' => null,
                'producto_id' => $producto->id,
                'nombre' => $producto->nombre,
                'descripcion' => 'Material de prueba',
                'unidad_medida' => 'unidad',
                'cantidad' => 3,
                'precio_unitario' => 50,
                'total_linea' => 150,
            ],
        ];

        $proforma->items()->createMany($items);

        return $proforma->load('items');
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    private function crearOrden(User $usuario, Proforma $proforma, array $datos = []): TestResponse
    {
        return $this->actingAs($usuario)->postJson('/api/v1/ordenes', [
            'proforma_id' => $proforma->id,
            ...$datos,
        ]);
    }

    private function soloPostgres(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Constraint exclusivo de PostgreSQL.');
        }
    }
}
