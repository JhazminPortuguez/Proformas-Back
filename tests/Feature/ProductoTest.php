<?php

namespace Tests\Feature;

use App\Enums\Rol;
use App\Models\MovimientoInventario;
use App\Models\Producto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductoTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_crea_material_con_datos_de_stock(): void
    {
        $admin = User::factory()->create(['rol' => Rol::Admin]);

        $this->actingAs($admin)->postJson('/api/v1/productos', [
            'nombre' => 'Tóner de prueba',
            'precio_unitario' => 250.50,
            'unidad_medida' => 'unidad',
            'stock_actual' => 12.5,
            'stock_minimo' => 3,
            'costo_promedio' => 180.25,
        ])->assertCreated()
            ->assertJsonPath('stock_actual', 12.5)
            ->assertJsonPath('stock_minimo', 3)
            ->assertJsonPath('costo_promedio', 180.25);

        $this->assertDatabaseHas('productos', [
            'nombre' => 'Tóner de prueba',
            'stock_actual' => 12.5,
            'stock_minimo' => 3,
            'costo_promedio' => 180.25,
        ]);
    }

    public function test_operario_no_edita_materiales(): void
    {
        $operario = User::factory()->create(['rol' => Rol::Operario]);
        $producto = Producto::factory()->create();

        $this->actingAs($operario)->putJson("/api/v1/productos/{$producto->id}", [
            'precio_unitario' => 1,
        ])->assertForbidden();
    }

    public function test_creacion_con_stock_inicial_registra_movimiento(): void
    {
        $admin = User::factory()->create(['rol' => Rol::Admin]);

        $response = $this->actingAs($admin)->postJson('/api/v1/productos', [
            'nombre' => 'Material con stock inicial',
            'precio_unitario' => 50,
            'stock_actual' => 4,
            'costo_promedio' => 30,
        ])->assertCreated();

        $this->assertDatabaseHas('movimientos_inventario', [
            'producto_id' => $response->json('id'),
            'tipo' => 'ENTRADA',
            'cantidad' => 4,
            'stock_anterior' => 0,
            'stock_nuevo' => 4,
        ]);
    }

    public function test_no_permite_editar_stock_directamente(): void
    {
        $admin = User::factory()->create(['rol' => Rol::Admin]);
        $producto = Producto::factory()->create(['stock_actual' => 10]);

        $this->actingAs($admin)->putJson("/api/v1/productos/{$producto->id}", [
            'stock_actual' => 20,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('stock_actual');

        $this->assertSame('10.000', $producto->fresh()->stock_actual);
    }

    public function test_no_elimina_producto_con_movimientos(): void
    {
        $admin = User::factory()->create(['rol' => Rol::Admin]);
        $producto = Producto::factory()->create();

        MovimientoInventario::factory()->create([
            'producto_id' => $producto->id,
        ]);

        $this->actingAs($admin)
            ->deleteJson("/api/v1/productos/{$producto->id}")
            ->assertStatus(409);
    }
}
