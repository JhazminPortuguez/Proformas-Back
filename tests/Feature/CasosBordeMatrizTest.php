<?php

namespace Tests\Feature;

use App\Enums\Rol;
use App\Models\Cliente;
use App\Models\CuentaPorCobrar;
use App\Models\Empresa;
use App\Models\PagoCuenta;
use App\Models\Producto;
use App\Models\Proforma;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Casos borde P0 de la matriz (CASOS_BORDE_COMPANERO) que no tenían una
 * prueba propia: T-02, P-01, P-02, P-03, I-02 y X-12.
 */
class CasosBordeMatrizTest extends TestCase
{
    use RefreshDatabase;

    /** T-02: el cambio de rol se aplica en la siguiente petición de la misma sesión. */
    public function test_cambio_de_rol_durante_la_sesion_usa_el_rol_actual(): void
    {
        $admin = User::factory()->create(['rol' => Rol::Admin]);
        $otroAdmin = User::factory()->create(['rol' => Rol::Admin]);

        $this->actingAs($otroAdmin)->getJson('/api/v1/users')->assertOk();

        // El primer admin le quita el rol mientras la sesión sigue abierta.
        $this->actingAs($admin)->putJson("/api/v1/users/{$otroAdmin->id}", ['rol' => 'operario'])->assertOk();

        $this->actingAs($otroAdmin->fresh())->getJson('/api/v1/users')->assertForbidden();
        $this->actingAs($otroAdmin->fresh())->getJson('/api/v1/clientes')->assertOk();
    }

    /** P-01 y P-02: sin ítems, cantidad 0, negativa o con más de 3 decimales. */
    public function test_proforma_sin_items_o_con_cantidades_invalidas_es_422(): void
    {
        $usuario = User::factory()->create(['rol' => Rol::Operario]);
        $cliente = Cliente::factory()->create();
        $producto = Producto::factory()->create(['precio_unitario' => 10]);

        $this->actingAs($usuario)->postJson('/api/v1/proformas', [
            'cliente_id' => $cliente->id,
            'fecha_emision' => '2026-11-01',
            'items' => [],
        ])->assertStatus(422)->assertJsonValidationErrors('items');

        foreach ([0, -1, 1.2345] as $cantidad) {
            $this->actingAs($usuario)->postJson('/api/v1/proformas', [
                'cliente_id' => $cliente->id,
                'fecha_emision' => '2026-11-01',
                'items' => [['producto_id' => $producto->id, 'cantidad' => $cantidad]],
            ])->assertStatus(422)->assertJsonValidationErrors('items.0.cantidad');
        }

        $this->assertSame(0, Proforma::query()->count());
    }

    /** P-03: precio negativo en línea libre es 422 (el precio 0 queda como decisión). */
    public function test_linea_libre_con_precio_negativo_es_422(): void
    {
        $usuario = User::factory()->create(['rol' => Rol::Operario]);
        $cliente = Cliente::factory()->create();

        $this->actingAs($usuario)->postJson('/api/v1/proformas', [
            'cliente_id' => $cliente->id,
            'fecha_emision' => '2026-11-01',
            'items' => [['tipo' => 'LIBRE', 'descripcion' => 'Ajuste', 'cantidad' => 1, 'precio_unitario' => -5]],
        ])->assertStatus(422)->assertJsonValidationErrors('items.0.precio_unitario');
    }

    /** I-02: costo de entrada negativo es 422 y el stock no cambia. */
    public function test_entrada_con_costo_negativo_es_422(): void
    {
        $admin = User::factory()->create(['rol' => Rol::Admin]);
        $producto = Producto::factory()->create(['stock_actual' => 5, 'costo_promedio' => 10]);

        $this->actingAs($admin)->postJson('/api/v1/inventario/entradas', [
            'producto_id' => $producto->id,
            'cantidad' => 2,
            'costo_unitario' => -1,
            'motivo' => 'Compra',
        ])->assertStatus(422)->assertJsonValidationErrors('costo_unitario');

        $this->assertSame('5.000', $producto->fresh()->stock_actual);
    }

    /** R-08: un cliente de otra empresa no sirve como filtro de un reporte. */
    public function test_filtro_de_reporte_con_cliente_de_otra_empresa_es_422(): void
    {
        $admin = User::factory()->create(['rol' => Rol::Admin]);
        $otraEmpresa = Empresa::factory()->create();
        $ajeno = Cliente::query()->withoutGlobalScopes()->create([
            'empresa_id' => $otraEmpresa->id,
            'nombre_razon' => 'Cliente ajeno',
            'estado' => 'activo',
        ]);

        foreach (['ordenes', 'servicios', 'inventario', 'cobros', 'clientes'] as $reporte) {
            $this->actingAs($admin)->getJson("/api/v1/reportes/{$reporte}?cliente_id={$ajeno->id}")
                ->assertStatus(422)
                ->assertJsonValidationErrors('cliente_id');
        }
    }

    /** X-12: pagar o ver una cuenta de otra empresa responde 404 y no crea el pago. */
    public function test_pago_en_cuenta_de_otra_empresa_responde_404(): void
    {
        $cuenta = CuentaPorCobrar::factory()->create(['saldo' => 100, 'monto_total' => 100]);
        $otraEmpresa = Empresa::factory()->create();
        $adminAjeno = User::factory()->admin()->create(['empresa_id' => $otraEmpresa->id]);

        $this->actingAs($adminAjeno)->getJson("/api/v1/cuentas-por-cobrar/{$cuenta->id}")->assertNotFound();
        $this->actingAs($adminAjeno)->postJson("/api/v1/cuentas-por-cobrar/{$cuenta->id}/pagos", [
            'monto' => 10,
            'metodo' => 'EFECTIVO',
        ])->assertNotFound();

        $this->assertSame(0, PagoCuenta::query()->count());
    }
}
