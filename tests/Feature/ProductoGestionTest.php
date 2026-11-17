<?php

namespace Tests\Feature;

use App\Enums\Rol;
use App\Enums\TipoMovimientoInventario;
use App\Models\MovimientoInventario;
use App\Models\OrdenItem;
use App\Models\Producto;
use App\Models\ProformaItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Formulario de productos de Inventario: alta con stock inicial como ENTRADA,
 * edición, activar/desactivar y eliminación protegida. Solo el admin.
 */
class ProductoGestionTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['rol' => Rol::Admin]);
    }

    public function test_el_stock_inicial_queda_como_entrada_con_su_costo(): void
    {
        $admin = $this->admin();

        $id = $this->actingAs($admin)->postJson('/api/v1/productos', [
            'nombre' => 'Cable UTP cat 6',
            'unidad_medida' => 'metro',
            'precio_unitario' => 8.5,
            'costo_promedio' => 5.25,
            'stock_minimo' => 20,
            'stock_actual' => 150,
        ])->assertCreated()->json('id');

        $movimiento = MovimientoInventario::query()->where('producto_id', $id)->sole();

        $this->assertSame(TipoMovimientoInventario::Entrada, $movimiento->tipo);
        $this->assertEquals(150, (float) $movimiento->cantidad);
        $this->assertEquals(5.25, (float) $movimiento->costo_unitario);
        $this->assertEquals(0, (float) $movimiento->stock_anterior);
        $this->assertEquals(150, (float) $movimiento->stock_nuevo);
        $this->assertSame($admin->id, $movimiento->created_by);
    }

    public function test_sin_stock_inicial_no_hay_movimiento(): void
    {
        $id = $this->actingAs($this->admin())->postJson('/api/v1/productos', [
            'nombre' => 'Conector RJ45',
            'precio_unitario' => 2,
        ])->assertCreated()->json('id');

        $this->assertSame(0, MovimientoInventario::query()->where('producto_id', $id)->count());
        $this->assertEquals(0, (float) Producto::findOrFail($id)->stock_actual);
    }

    public function test_los_errores_salen_en_espanol_con_los_nombres_del_formulario(): void
    {
        $errores = $this->actingAs($this->admin())->postJson('/api/v1/productos', [
            'nombre' => '',
            'unidad_medida' => str_repeat('u', 31),
            'precio_unitario' => 10.555,
            'costo_promedio' => -1,
            'stock_actual' => 1.2345,
            'stock_minimo' => -2,
        ])->assertStatus(422)->json('errors');

        $esperados = [
            'nombre' => 'El nombre del producto es obligatorio.',
            'unidad_medida' => 'El campo unidad de medida no debe tener más de 30 caracteres.',
            'precio_unitario' => 'El precio de venta debe tener como máximo 2 decimales.',
            'costo_promedio' => 'El costo no puede ser negativo.',
            'stock_actual' => 'El stock inicial debe tener como máximo 3 decimales.',
            'stock_minimo' => 'El stock mínimo no puede ser negativo.',
        ];

        foreach ($esperados as $campo => $mensaje) {
            $this->assertSame($mensaje, $errores[$campo][0] ?? null, $campo);
        }

        $this->assertSame(0, Producto::query()->count());
    }

    public function test_al_editar_no_se_cambian_stock_ni_costo(): void
    {
        $producto = Producto::factory()->create(['stock_actual' => 5, 'costo_promedio' => 10]);

        $errores = $this->actingAs($this->admin())->putJson("/api/v1/productos/{$producto->id}", [
            'nombre' => 'Otro nombre',
            'stock_actual' => 50,
            'costo_promedio' => 1,
        ])->assertStatus(422)->json('errors');

        $this->assertSame('El stock se cambia con una entrada o un ajuste de inventario.', $errores['stock_actual'][0]);
        $this->assertSame('El costo cambia solo con las entradas de inventario.', $errores['costo_promedio'][0]);
    }

    public function test_el_admin_desactiva_y_reactiva_y_el_inactivo_sale_del_buscador(): void
    {
        $admin = $this->admin();
        $producto = Producto::factory()->create(['nombre' => 'Canaleta 20x10', 'activo' => true]);

        $this->actingAs($admin)->putJson("/api/v1/productos/{$producto->id}", ['activo' => false])
            ->assertOk()->assertJsonPath('activo', false);

        $this->actingAs($admin)->getJson('/api/v1/productos?activo=1&search=Canaleta')
            ->assertOk()->assertJsonCount(0, 'data');

        $this->actingAs($admin)->putJson("/api/v1/productos/{$producto->id}", ['activo' => true])
            ->assertOk()->assertJsonPath('activo', true);

        $this->actingAs($admin)->getJson('/api/v1/productos?activo=1&search=Canaleta')
            ->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_el_operario_no_crea_edita_desactiva_ni_elimina(): void
    {
        $operario = User::factory()->create(['rol' => Rol::Operario]);
        $producto = Producto::factory()->create();

        $this->actingAs($operario)->postJson('/api/v1/productos', ['nombre' => 'X', 'precio_unitario' => 1])->assertForbidden();
        $this->actingAs($operario)->putJson("/api/v1/productos/{$producto->id}", ['activo' => false])->assertForbidden();
        $this->actingAs($operario)->deleteJson("/api/v1/productos/{$producto->id}")->assertForbidden();
        $this->assertTrue($producto->fresh()->activo);
    }

    public function test_no_se_elimina_si_esta_en_ordenes_proformas_o_movimientos(): void
    {
        $admin = $this->admin();

        $enOrden = OrdenItem::factory()->create()->producto;
        $this->actingAs($admin)->deleteJson("/api/v1/productos/{$enOrden->id}")
            ->assertStatus(409)
            ->assertJsonPath('message', 'No se puede eliminar el producto porque está en órdenes de servicio. Desactívalo en su lugar.');

        $enProforma = ProformaItem::factory()->create(['producto_id' => Producto::factory()])->producto;
        $this->actingAs($admin)->deleteJson("/api/v1/productos/{$enProforma->id}")->assertStatus(409);

        $conMovimientos = MovimientoInventario::factory()->create()->producto;
        $this->actingAs($admin)->deleteJson("/api/v1/productos/{$conMovimientos->id}")->assertStatus(409);

        $this->assertNotNull($enOrden->fresh());
        $this->assertNotNull($enProforma->fresh());
        $this->assertNotNull($conMovimientos->fresh());

        $libre = Producto::factory()->create();
        $this->actingAs($admin)->deleteJson("/api/v1/productos/{$libre->id}")->assertNoContent();
        $this->assertNull($libre->fresh());
    }
}
