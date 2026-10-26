<?php

namespace Tests\Feature;

use App\Enums\Rol;
use App\Enums\TipoMovimientoInventario;
use App\Models\Empresa;
use App\Models\OrdenItem;
use App\Models\OrdenServicio;
use App\Models\Producto;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InventarioTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_registra_entrada_y_actualiza_stock_y_costo_promedio_ponderado(): void
    {
        $admin = $this->admin();
        $producto = Producto::factory()->create([
            'nombre' => 'Disco SSD',
            'stock_actual' => 10,
            'stock_minimo' => 2,
            'costo_promedio' => 100,
        ]);

        $this->actingAs($admin)->postJson('/api/v1/inventario/entradas', [
            'producto_id' => $producto->id,
            'cantidad' => 5,
            'costo_unitario' => 130,
            'motivo' => 'Compra a proveedor',
        ])
            ->assertCreated()
            ->assertJsonPath('tipo', 'ENTRADA')
            ->assertJsonPath('producto_id', $producto->id)
            ->assertJsonPath('cantidad', 5)
            ->assertJsonPath('stock_anterior', 10)
            ->assertJsonPath('stock_nuevo', 15)
            ->assertJsonPath('costo_unitario', 130)
            ->assertJsonPath('motivo', 'Compra a proveedor')
            ->assertJsonPath('created_by', $admin->id)
            ->assertJsonPath('producto.id', $producto->id)
            ->assertJsonPath('producto.stock_actual', 15)
            ->assertJsonPath('producto.costo_promedio', 110)
            ->assertJsonPath('usuario.id', $admin->id);

        $this->assertDatabaseHas('productos', [
            'id' => $producto->id,
            'stock_actual' => 15,
            'costo_promedio' => 110,
        ]);

        $this->assertDatabaseHas('movimientos_inventario', [
            'producto_id' => $producto->id,
            'tipo' => 'ENTRADA',
            'cantidad' => 5,
            'stock_anterior' => 10,
            'stock_nuevo' => 15,
            'costo_unitario' => 130,
            'created_by' => $admin->id,
        ]);
    }

    public function test_entrada_sin_costo_conserva_el_costo_promedio_actual(): void
    {
        $admin = $this->admin();
        $producto = Producto::factory()->create([
            'stock_actual' => 10,
            'costo_promedio' => 80,
        ]);

        $this->actingAs($admin)->postJson('/api/v1/inventario/entradas', [
            'producto_id' => $producto->id,
            'cantidad' => '2.500',
        ])
            ->assertCreated()
            ->assertJsonPath('cantidad', 2.5)
            ->assertJsonPath('stock_anterior', 10)
            ->assertJsonPath('stock_nuevo', 12.5)
            ->assertJsonPath('costo_unitario', 80)
            ->assertJsonPath('producto.costo_promedio', 80);

        $this->assertSame('12.500', $producto->fresh()->stock_actual);
        $this->assertSame('80.00', $producto->fresh()->costo_promedio);
    }

    public function test_entrada_rechaza_cantidad_invalida_y_producto_de_otra_empresa(): void
    {
        $admin = $this->admin();
        $producto = Producto::factory()->create(['stock_actual' => 10]);
        $otraEmpresa = Empresa::factory()->create();
        $ajeno = Producto::factory()->create(['empresa_id' => $otraEmpresa->id]);

        foreach ([0, -1, '1.2345'] as $cantidad) {
            $this->actingAs($admin)->postJson('/api/v1/inventario/entradas', [
                'producto_id' => $producto->id,
                'cantidad' => $cantidad,
            ])
                ->assertStatus(422)
                ->assertJsonValidationErrors('cantidad');
        }

        $this->actingAs($admin)->postJson('/api/v1/inventario/entradas', [
            'producto_id' => $ajeno->id,
            'cantidad' => 1,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('producto_id');

        $this->assertDatabaseCount('movimientos_inventario', 0);
        $this->assertSame('10.000', $producto->fresh()->stock_actual);
    }

    public function test_solo_admin_registra_entradas_y_ajustes(): void
    {
        $operario = User::factory()->create(['rol' => Rol::Operario]);
        $producto = Producto::factory()->create(['stock_actual' => 10]);

        $this->actingAs($operario)->postJson('/api/v1/inventario/entradas', [
            'producto_id' => $producto->id,
            'cantidad' => 1,
        ])->assertForbidden();

        $this->actingAs($operario)->postJson('/api/v1/inventario/ajustes', [
            'producto_id' => $producto->id,
            'stock_nuevo' => 1,
            'motivo' => 'Prueba',
        ])->assertForbidden();

        $this->assertDatabaseCount('movimientos_inventario', 0);
    }

    public function test_ajuste_sube_y_baja_stock_con_cantidad_firmada(): void
    {
        $admin = $this->admin();
        $producto = Producto::factory()->create([
            'nombre' => 'Teclado Ñandú',
            'stock_actual' => 10,
            'costo_promedio' => 25,
        ]);

        $this->actingAs($admin)->postJson('/api/v1/inventario/ajustes', [
            'producto_id' => $producto->id,
            'stock_nuevo' => 7,
            'motivo' => 'Conteo físico',
        ])
            ->assertCreated()
            ->assertJsonPath('tipo', 'AJUSTE')
            ->assertJsonPath('cantidad', -3)
            ->assertJsonPath('stock_anterior', 10)
            ->assertJsonPath('stock_nuevo', 7)
            ->assertJsonPath('costo_unitario', 25)
            ->assertJsonPath('motivo', 'Conteo físico');

        $this->assertSame('7.000', $producto->fresh()->stock_actual);

        $this->actingAs($admin)->postJson('/api/v1/inventario/ajustes', [
            'producto_id' => $producto->id,
            'stock_nuevo' => 9.5,
            'motivo' => 'Ingreso no registrado',
        ])
            ->assertCreated()
            ->assertJsonPath('cantidad', 2.5)
            ->assertJsonPath('stock_anterior', 7)
            ->assertJsonPath('stock_nuevo', 9.5);

        $this->assertDatabaseHas('movimientos_inventario', [
            'producto_id' => $producto->id,
            'tipo' => 'AJUSTE',
            'cantidad' => -3,
        ]);
        $this->assertDatabaseHas('movimientos_inventario', [
            'producto_id' => $producto->id,
            'tipo' => 'AJUSTE',
            'cantidad' => 2.5,
        ]);
    }

    public function test_ajuste_exige_motivo_rechaza_delta_cero_y_stock_negativo(): void
    {
        $admin = $this->admin();
        $producto = Producto::factory()->create(['stock_actual' => 10]);

        $this->actingAs($admin)->postJson('/api/v1/inventario/ajustes', [
            'producto_id' => $producto->id,
            'stock_nuevo' => 5,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('motivo');

        $this->actingAs($admin)->postJson('/api/v1/inventario/ajustes', [
            'producto_id' => $producto->id,
            'stock_nuevo' => 10,
            'motivo' => 'Sin cambios',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('stock_nuevo');

        $this->actingAs($admin)->postJson('/api/v1/inventario/ajustes', [
            'producto_id' => $producto->id,
            'stock_nuevo' => -1,
            'motivo' => 'Intento inválido',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('stock_nuevo');

        $this->assertDatabaseCount('movimientos_inventario', 0);
        $this->assertSame('10.000', $producto->fresh()->stock_actual);
    }

    public function test_listado_busca_con_acentos_filtra_bajo_stock_y_pagina(): void
    {
        $admin = $this->admin();
        $disco = Producto::factory()->create([
            'nombre' => 'Disco Ñandú',
            'descripcion' => 'Unidad de estado sólido',
            'stock_actual' => 2,
            'stock_minimo' => 5,
            'costo_promedio' => 10,
            'activo' => true,
        ]);
        $cable = Producto::factory()->create([
            'nombre' => 'Cable HDMI',
            'stock_actual' => 5,
            'stock_minimo' => 5,
            'costo_promedio' => 20,
            'activo' => true,
        ]);
        $toner = Producto::factory()->create([
            'nombre' => 'Tóner Láser',
            'stock_actual' => 20,
            'stock_minimo' => 1,
            'costo_promedio' => 30,
            'activo' => false,
        ]);

        $this->actingAs($admin)->postJson('/api/v1/inventario/entradas', [
            'producto_id' => $disco->id,
            'cantidad' => 1,
        ])->assertCreated();

        $this->actingAs($admin)->getJson('/api/v1/inventario?search=nandu')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $disco->id)
            ->assertJsonPath('data.0.stock_bajo', true)
            ->assertJsonPath('data.0.movimientos_count', 1)
            ->assertJsonPath('data.0.valor_stock', 30);

        $this->actingAs($admin)->getJson('/api/v1/inventario?search=toner')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $toner->id);

        $this->actingAs($admin)->getJson('/api/v1/inventario?bajo_stock=1')
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.id', $cable->id)
            ->assertJsonPath('data.1.id', $disco->id);

        $this->actingAs($admin)->getJson('/api/v1/inventario?bajo_stock=0')
            ->assertOk()
            ->assertJsonPath('meta.total', 3);

        $this->actingAs($admin)->getJson('/api/v1/inventario?activo=0')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $toner->id);

        $this->actingAs($admin)->getJson('/api/v1/inventario?per_page=1')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.last_page', 3);
    }

    public function test_resumen_calcula_productos_bajo_stock_valor_y_movimientos_del_mes(): void
    {
        $admin = $this->admin();
        $productoA = Producto::factory()->create([
            'stock_actual' => 2,
            'stock_minimo' => 5,
            'costo_promedio' => 10,
        ]);
        Producto::factory()->create([
            'stock_actual' => 10,
            'stock_minimo' => 1,
            'costo_promedio' => 20,
        ]);

        $this->actingAs($admin)->postJson('/api/v1/inventario/entradas', [
            'producto_id' => $productoA->id,
            'cantidad' => 1,
        ])->assertCreated();

        $this->actingAs($admin)->getJson('/api/v1/inventario/resumen')
            ->assertOk()
            ->assertJsonPath('productos', 2)
            ->assertJsonPath('bajo_stock', 1)
            ->assertJsonPath('valor_inventario', 230)
            ->assertJsonPath('movimientos_mes', 1);
    }

    public function test_movimientos_filtra_por_producto_tipo_texto_y_fechas(): void
    {
        $admin = $this->admin();
        $producto = Producto::factory()->create([
            'nombre' => 'Teclado Ñandú',
            'stock_actual' => 10,
            'costo_promedio' => 15,
        ]);
        $otro = Producto::factory()->create([
            'nombre' => 'Mouse óptico',
            'stock_actual' => 10,
            'costo_promedio' => 8,
        ]);

        $this->actingAs($admin)->postJson('/api/v1/inventario/entradas', [
            'producto_id' => $producto->id,
            'cantidad' => 2,
            'motivo' => 'Compra de reposición',
        ])->assertCreated();

        $this->actingAs($admin)->postJson('/api/v1/inventario/ajustes', [
            'producto_id' => $producto->id,
            'stock_nuevo' => 11,
            'motivo' => 'Conteo Ñandú',
        ])->assertCreated();

        $this->actingAs($admin)->postJson('/api/v1/inventario/entradas', [
            'producto_id' => $otro->id,
            'cantidad' => 1,
            'motivo' => 'Compra',
        ])->assertCreated();

        $this->actingAs($admin)->getJson('/api/v1/inventario/movimientos?search=nandu')
            ->assertOk()
            ->assertJsonPath('meta.total', 2);

        $this->actingAs($admin)->getJson('/api/v1/inventario/movimientos?search=reposicion')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.motivo', 'Compra de reposición')
            ->assertJsonPath('data.0.producto.id', $producto->id)
            ->assertJsonPath('data.0.usuario.id', $admin->id);

        $this->actingAs($admin)->getJson('/api/v1/inventario/movimientos?tipo=AJUSTE')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.tipo', 'AJUSTE');

        $this->actingAs($admin)->getJson('/api/v1/inventario/movimientos?producto_id='.$otro->id)
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.producto_id', $otro->id);

        $hoy = now()->toDateString();
        $this->actingAs($admin)->getJson("/api/v1/inventario/movimientos?desde={$hoy}&hasta={$hoy}")
            ->assertOk()
            ->assertJsonPath('meta.total', 3);

        $this->actingAs($admin)->getJson('/api/v1/inventario/movimientos?per_page=2')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.last_page', 2);

        $this->actingAs($admin)->getJson('/api/v1/inventario/movimientos?tipo=INVALIDO')
            ->assertStatus(422)
            ->assertJsonValidationErrors('tipo');
    }

    public function test_operario_consulta_inventario_y_movimientos_pero_cliente_no(): void
    {
        $operario = User::factory()->create(['rol' => Rol::Operario]);
        $cliente = User::factory()->create(['rol' => Rol::Cliente]);
        Producto::factory()->create();

        $this->actingAs($operario)->getJson('/api/v1/inventario')->assertOk();
        $this->actingAs($operario)->getJson('/api/v1/inventario/resumen')->assertOk();
        $this->actingAs($operario)->getJson('/api/v1/inventario/movimientos')->assertOk();

        $this->actingAs($cliente)->getJson('/api/v1/inventario')->assertForbidden();
        $this->actingAs($cliente)->getJson('/api/v1/inventario/resumen')->assertForbidden();
        $this->actingAs($cliente)->getJson('/api/v1/inventario/movimientos')->assertForbidden();
        $this->actingAs($cliente)->postJson('/api/v1/inventario/entradas')->assertForbidden();
        $this->actingAs($cliente)->postJson('/api/v1/inventario/ajustes')->assertForbidden();
    }

    public function test_restricciones_check_de_postgresql(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Las restricciones CHECK solo se aplican en PostgreSQL.');
        }

        $admin = $this->admin();
        $producto = Producto::factory()->create(['stock_actual' => 10]);

        $this->afirmarViolacion(
            'movimientos_inventario_cantidad_check',
            fn () => $this->insertarMovimiento($producto, ['cantidad' => 0]),
        );

        $this->afirmarViolacion(
            'movimientos_inventario_stock_check',
            fn () => $this->insertarMovimiento($producto, ['stock_anterior' => -1]),
        );

        $this->afirmarViolacion(
            'movimientos_inventario_tipo_check',
            fn () => $this->insertarMovimiento($producto, ['tipo' => 'OTRO']),
        );

        $orden = OrdenServicio::factory()->enProceso()->create(['operario_id' => $admin->id]);
        $item = OrdenItem::factory()->create([
            'orden_id' => $orden->id,
            'producto_id' => $producto->id,
            'cantidad' => 2,
        ]);

        $this->afirmarViolacion(
            'orden_items_cantidad_consumida_check',
            fn () => DB::table('orden_items')->where('id', $item->id)->update(['cantidad_consumida' => 3]),
        );
    }

    private function admin(): User
    {
        return User::factory()->create(['rol' => Rol::Admin]);
    }

    /**
     * @param  array<string, mixed>  $sobrescribir
     */
    private function insertarMovimiento(Producto $producto, array $sobrescribir = []): void
    {
        DB::table('movimientos_inventario')->insert([
            'empresa_id' => $producto->empresa_id,
            'producto_id' => $producto->id,
            'tipo' => TipoMovimientoInventario::Entrada->value,
            'cantidad' => 1,
            'costo_unitario' => 0,
            'stock_anterior' => 0,
            'stock_nuevo' => 1,
            'motivo' => 'Test',
            'created_at' => now(),
            'updated_at' => now(),
            ...$sobrescribir,
        ]);
    }

    private function afirmarViolacion(string $constraint, callable $operacion): void
    {
        try {
            // Un savepoint evita dejar abortada la transacción de RefreshDatabase
            // cuando PostgreSQL rechaza la sentencia.
            DB::transaction($operacion);
        } catch (QueryException $exception) {
            $this->assertStringContainsString($constraint, $exception->getMessage());

            return;
        }

        $this->fail("Se esperaba una violación de la restricción {$constraint}.");
    }
}
