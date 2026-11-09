<?php

namespace Tests\Feature;

use App\Actions\Ordenes\CancelarOrden;
use App\Enums\ConceptoCuenta;
use App\Enums\EstadoOrden;
use App\Enums\Rol;
use App\Enums\TipoMovimientoInventario;
use App\Enums\TipoOrdenEvento;
use App\Models\CuentaPorCobrar;
use App\Models\MovimientoInventario;
use App\Models\OrdenEvento;
use App\Models\OrdenItem;
use App\Models\OrdenServicio;
use App\Models\Producto;
use App\Models\Proforma;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Caso real: armado de PC. El operario empezó y consumió materiales; el
 * cliente cancela a mitad del trabajo.
 */
class CancelarOrdenConMaterialesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private OrdenServicio $orden;

    private Producto $ram;

    private Producto $ssd;

    private OrdenItem $lineaRam;

    private OrdenItem $lineaSsd;

    protected function setUp(): void
    {
        parent::setUp();

        // 8/11/2026 a las 21:00 en Bolivia (en UTC ya es 9/11).
        $this->travelTo(CarbonImmutable::parse('2026-11-08 21:00:00', 'America/La_Paz'));

        $this->admin = User::factory()->create(['rol' => Rol::Admin]);
        $operario = User::factory()->create(['rol' => Rol::Operario]);

        $proforma = Proforma::factory()->aceptada()->create([
            'moneda' => 'BOB',
            'descuento' => 50,
            'impuesto_tasa' => 13,
        ]);
        $this->orden = OrdenServicio::factory()->paraProforma($proforma)->create([
            'numero' => 'OS-2026-000031',
            'operario_id' => $operario->id,
            'estado' => EstadoOrden::EnProceso,
        ]);

        $this->ram = Producto::factory()->create(['nombre' => 'Memoria RAM 8 GB', 'stock_actual' => 10, 'costo_promedio' => 120]);
        $this->ssd = Producto::factory()->create(['nombre' => 'Disco SSD 480 GB', 'stock_actual' => 5, 'costo_promedio' => 200]);

        $this->lineaRam = OrdenItem::factory()->create([
            'orden_id' => $this->orden->id,
            'producto_id' => $this->ram->id,
            'descripcion' => 'Memoria RAM 8 GB',
            'cantidad' => 2,
            'precio_unitario' => 150,
        ]);
        $this->lineaSsd = OrdenItem::factory()->create([
            'orden_id' => $this->orden->id,
            'producto_id' => $this->ssd->id,
            'descripcion' => 'Disco SSD 480 GB',
            'cantidad' => 1,
            'precio_unitario' => 280.5,
        ]);
        OrdenItem::factory()->servicio()->create([
            'orden_id' => $this->orden->id,
            'descripcion' => 'Armado y configuración',
            'cantidad' => 1,
            'precio_unitario' => 200,
        ]);

        // El operario consume las 2 RAM y el SSD.
        $this->actingAs($this->admin)->postJson("/api/v1/ordenes/{$this->orden->id}/consumir")->assertOk();

        $this->assertSame('8.000', $this->ram->fresh()->stock_actual);
        $this->assertSame('4.000', $this->ssd->fresh()->stock_actual);
    }

    public function test_cancela_devolviendo_una_parte_y_cobrando_el_resto(): void
    {
        $respuesta = $this->cancelar([
            'motivo' => 'El cliente desistió del armado.',
            'devoluciones' => [
                ['orden_item_id' => $this->lineaRam->id, 'cantidad' => 1],
                ['orden_item_id' => $this->lineaSsd->id, 'cantidad' => 0],
            ],
            'cobrar_materiales' => true,
        ]);

        // Cobro: 1 RAM × 150 + 1 SSD × 280,50 = 430,50 (sin descuento ni impuesto).
        $respuesta->assertOk()
            ->assertJsonPath('estado', 'CANCELADA')
            ->assertJsonPath('cuenta.concepto', 'CANCELACION')
            ->assertJsonPath('cuenta.monto_total', 430.5)
            ->assertJsonPath('cuenta.saldo', 430.5);

        // Stock: vuelve 1 RAM con un movimiento DEVOLUCION ligado a la orden.
        $this->assertSame('9.000', $this->ram->fresh()->stock_actual);
        $this->assertSame('4.000', $this->ssd->fresh()->stock_actual);

        $devolucion = MovimientoInventario::query()
            ->where('tipo', TipoMovimientoInventario::Devolucion->value)
            ->sole();
        $this->assertSame($this->orden->id, $devolucion->orden_id);
        $this->assertSame($this->lineaRam->id, $devolucion->orden_item_id);
        $this->assertSame('1.000', $devolucion->cantidad);
        $this->assertSame('8.000', $devolucion->stock_anterior);
        $this->assertSame('9.000', $devolucion->stock_nuevo);
        $this->assertSame('Devolución por cancelación de la orden OS-2026-000031', $devolucion->motivo);

        // La línea queda con lo que de verdad se usó.
        $this->assertSame('1.000', $this->lineaRam->fresh()->cantidad_consumida);

        // Cuenta por cobrar de cancelación, emitida hoy en Bolivia, a 30 días.
        $cuenta = CuentaPorCobrar::query()->where('orden_id', $this->orden->id)->sole();
        $this->assertSame(ConceptoCuenta::Cancelacion, $cuenta->concepto);
        $this->assertSame('2026-11-08', $cuenta->fecha_emision->toDateString());
        $this->assertSame('2026-12-08', $cuenta->fecha_vencimiento->toDateString());
        $this->assertSame('430.50', $cuenta->monto_total);
        $this->assertSame('CXC-2026-000001', $cuenta->numero);
        $this->assertStringContainsString('Cobro por cancelación de la orden OS-2026-000031', $cuenta->observaciones);
        $this->assertStringContainsString('sin el descuento ni el impuesto de la proforma', $cuenta->observaciones);
        $this->assertStringContainsString('- Memoria RAM 8 GB: 1 unidad × Bs 150,00 = Bs 150,00 (consumido 2, devuelto 1)', $cuenta->observaciones);
        $this->assertStringContainsString('- Disco SSD 480 GB: 1 unidad × Bs 280,50 = Bs 280,50 (consumido 1, devuelto 0)', $cuenta->observaciones);
        $this->assertStringContainsString('Total: Bs 430,50', $cuenta->observaciones);

        // Historial de la orden.
        $evento = OrdenEvento::query()
            ->where('orden_id', $this->orden->id)
            ->where('tipo', TipoOrdenEvento::Cancelacion->value)
            ->sole();
        $this->assertSame(
            'Orden cancelada. Devuelto al stock: 1 Memoria RAM 8 GB. Cobro por cancelación CXC-2026-000001 por Bs 430,50.',
            $evento->descripcion,
        );
        $this->assertSame('El cliente desistió del armado.', $evento->metadata['motivo']);
        $this->assertSame(['cuenta_id' => $cuenta->id, 'numero' => 'CXC-2026-000001', 'monto' => 430.5], $evento->metadata['cobro']);
        $this->assertCount(2, $evento->metadata['materiales']);
        $this->assertSame(2.0, $evento->metadata['materiales'][0]['consumida'] + 0.0);

        // Se ve en Cuentas por cobrar con su concepto.
        $this->actingAs($this->admin)->getJson("/api/v1/cuentas-por-cobrar/{$cuenta->id}")
            ->assertOk()
            ->assertJsonPath('concepto', 'CANCELACION')
            ->assertJsonPath('concepto_etiqueta', 'Cancelación');

        // El consumo del reporte de inventario es neto de la devolución.
        $consumo = $this->actingAs($this->admin)
            ->getJson('/api/v1/reportes/inventario?desde=2026-11-01&hasta=2026-11-30')
            ->assertOk()
            ->json('consumo');
        $this->assertSame(2, $consumo['productos']);
    }

    public function test_sin_cobro_no_crea_cuenta_y_lo_no_devuelto_queda_como_consumo(): void
    {
        $this->cancelar([
            'motivo' => 'Cancelado sin cargo.',
            'devoluciones' => [['orden_item_id' => $this->lineaSsd->id, 'cantidad' => 1]],
        ])->assertOk()->assertJsonPath('cuenta', null);

        $this->assertSame(0, CuentaPorCobrar::query()->count());
        $this->assertSame('8.000', $this->ram->fresh()->stock_actual);
        $this->assertSame('5.000', $this->ssd->fresh()->stock_actual);
        $this->assertSame(
            'Orden cancelada. Devuelto al stock: 1 Disco SSD 480 GB.',
            OrdenEvento::query()->where('tipo', TipoOrdenEvento::Cancelacion->value)->sole()->descripcion,
        );
    }

    public function test_por_defecto_no_se_devuelve_nada(): void
    {
        $this->cancelar(['motivo' => 'Cliente canceló.'])->assertOk();

        $this->assertSame('8.000', $this->ram->fresh()->stock_actual);
        $this->assertSame(0, MovimientoInventario::query()->where('tipo', 'DEVOLUCION')->count());
        $this->assertSame(
            'Orden cancelada. No se devolvieron materiales al stock.',
            OrdenEvento::query()->where('tipo', TipoOrdenEvento::Cancelacion->value)->sole()->descripcion,
        );
    }

    public function test_si_se_devolvio_todo_no_hay_nada_que_cobrar(): void
    {
        $this->cancelar([
            'motivo' => 'Cliente canceló.',
            'devoluciones' => [
                ['orden_item_id' => $this->lineaRam->id, 'cantidad' => 2],
                ['orden_item_id' => $this->lineaSsd->id, 'cantidad' => 1],
            ],
            'cobrar_materiales' => true,
        ])->assertStatus(422)
            ->assertJsonPath('errors.cobrar_materiales.0', CancelarOrden::MENSAJE_NADA_QUE_COBRAR);

        $this->assertNothingChanged();
    }

    public function test_no_se_devuelve_mas_de_lo_consumido_ni_cantidades_negativas(): void
    {
        $this->cancelar([
            'motivo' => 'Cliente canceló.',
            'devoluciones' => [['orden_item_id' => $this->lineaRam->id, 'cantidad' => 3]],
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['devoluciones.0.cantidad' => 'No se puede devolver más de lo consumido de Memoria RAM 8 GB (2).']);

        $this->cancelar([
            'motivo' => 'Cliente canceló.',
            'devoluciones' => [['orden_item_id' => $this->lineaRam->id, 'cantidad' => -1]],
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['devoluciones.0.cantidad' => 'La cantidad devuelta no puede ser negativa.']);

        $servicio = OrdenItem::query()->where('orden_id', $this->orden->id)->where('tipo', 'SERVICIO')->sole();
        $this->cancelar([
            'motivo' => 'Cliente canceló.',
            'devoluciones' => [['orden_item_id' => $servicio->id, 'cantidad' => 1]],
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['devoluciones.0.orden_item_id' => 'La línea no es un material consumido de esta orden.']);

        $this->assertNothingChanged();
    }

    public function test_todo_o_nada_si_falla_la_cuenta_no_queda_ni_la_devolucion_ni_la_cancelacion(): void
    {
        // Un número de cuenta ya usado hace fallar la creación de la cuenta.
        CuentaPorCobrar::factory()->create(['numero' => 'CXC-2026-000001']);
        $antes = CuentaPorCobrar::query()->count();

        $this->withoutExceptionHandling();

        try {
            $this->cancelar([
                'motivo' => 'Cliente canceló.',
                'devoluciones' => [['orden_item_id' => $this->lineaRam->id, 'cantidad' => 1]],
                'cobrar_materiales' => true,
            ]);
            $this->fail('Se esperaba un error al crear la cuenta.');
        } catch (QueryException) {
            // Número de cuenta duplicado: se revierte toda la transacción.
        }

        $this->assertSame($antes, CuentaPorCobrar::query()->count());
        $this->assertNothingChanged();
    }

    public function test_solo_el_admin_cancela(): void
    {
        $operario = User::query()->findOrFail($this->orden->operario_id);

        $this->actingAs($operario)->postJson("/api/v1/ordenes/{$this->orden->id}/cancelar", [
            'motivo' => 'Intento del operario.',
            'cobrar_materiales' => true,
        ])->assertForbidden();

        $this->assertNothingChanged();
    }

    public function test_orden_sin_consumos_se_cancela_como_antes_y_no_admite_cobro(): void
    {
        $orden = OrdenServicio::factory()->create(['estado' => EstadoOrden::Pendiente]);

        $this->actingAs($this->admin)->postJson("/api/v1/ordenes/{$orden->id}/cancelar", [
            'motivo' => 'Cliente canceló.',
            'cobrar_materiales' => true,
        ])->assertStatus(422)
            ->assertJsonPath('errors.cobrar_materiales.0', 'La orden no tiene materiales consumidos que cobrar.');

        $this->actingAs($this->admin)->postJson("/api/v1/ordenes/{$orden->id}/cancelar", [
            'motivo' => 'Cliente canceló.',
        ])->assertOk()->assertJsonPath('estado', 'CANCELADA');

        $this->assertSame(
            'Orden cancelada.',
            OrdenEvento::query()->where('orden_id', $orden->id)->where('tipo', 'CANCELACION')->sole()->descripcion,
        );
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    private function cancelar(array $datos): TestResponse
    {
        return $this->actingAs($this->admin)->postJson("/api/v1/ordenes/{$this->orden->id}/cancelar", $datos);
    }

    private function assertNothingChanged(): void
    {
        $this->assertSame(EstadoOrden::EnProceso, $this->orden->fresh()->estado);
        $this->assertSame('8.000', $this->ram->fresh()->stock_actual);
        $this->assertSame('4.000', $this->ssd->fresh()->stock_actual);
        $this->assertSame('2.000', $this->lineaRam->fresh()->cantidad_consumida);
        $this->assertSame(0, MovimientoInventario::query()->where('tipo', 'DEVOLUCION')->count());
        $this->assertSame(0, OrdenEvento::query()->where('tipo', 'CANCELACION')->count());
    }
}
