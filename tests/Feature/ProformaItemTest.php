<?php

namespace Tests\Feature;

use App\Enums\Rol;
use App\Models\Cliente;
use App\Models\Producto;
use App\Models\Servicio;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProformaItemTest extends TestCase
{
    use RefreshDatabase;

    public function test_proforma_acepta_lineas_de_material_servicio_y_libre(): void
    {
        $usuario = User::factory()->create(['rol' => Rol::Operario]);
        $cliente = Cliente::factory()->create();
        $producto = Producto::factory()->create([
            'precio_unitario' => 100,
            'nombre' => 'Material X',
            'unidad_medida' => 'unidad',
        ]);
        $servicio = Servicio::factory()->create([
            'precio_base' => 50,
            'nombre' => 'Servicio Y',
        ]);

        $response = $this->actingAs($usuario)->postJson('/api/v1/proformas', [
            'cliente_id' => $cliente->id,
            'fecha_emision' => '2026-10-23',
            'items' => [
                ['tipo' => 'MATERIAL', 'producto_id' => $producto->id, 'cantidad' => 1],
                ['tipo' => 'SERVICIO', 'servicio_id' => $servicio->id, 'cantidad' => 2],
                [
                    'tipo' => 'LIBRE',
                    'descripcion' => 'Trabajo adicional',
                    'cantidad' => 1.5,
                    'precio_unitario' => 10.33,
                    'unidad_medida' => 'hora',
                ],
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('subtotal', 215.5)
            ->assertJsonPath('total', 215.5)
            ->assertJsonPath('items.0.tipo', 'MATERIAL')
            ->assertJsonPath('items.0.nombre', 'Material X')
            ->assertJsonPath('items.0.unidad_medida', 'unidad')
            ->assertJsonPath('items.1.tipo', 'SERVICIO')
            ->assertJsonPath('items.1.servicio_id', $servicio->id)
            ->assertJsonPath('items.1.nombre', 'Servicio Y')
            ->assertJsonPath('items.2.tipo', 'LIBRE')
            ->assertJsonPath('items.2.descripcion', 'Trabajo adicional')
            ->assertJsonPath('items.2.unidad_medida', 'hora')
            ->assertJsonPath('items.2.total_linea', 15.5);

        $this->assertDatabaseHas('proforma_items', [
            'tipo' => 'SERVICIO',
            'servicio_id' => $servicio->id,
            'producto_id' => null,
            'precio_unitario' => 50,
            'total_linea' => 100,
        ]);
    }

    public function test_permite_dos_lineas_del_mismo_producto(): void
    {
        $usuario = User::factory()->create(['rol' => Rol::Operario]);
        $cliente = Cliente::factory()->create();
        $producto = Producto::factory()->create(['precio_unitario' => 100]);

        $this->actingAs($usuario)->postJson('/api/v1/proformas', [
            'cliente_id' => $cliente->id,
            'fecha_emision' => '2026-10-23',
            'items' => [
                ['producto_id' => $producto->id, 'cantidad' => 1],
                ['producto_id' => $producto->id, 'cantidad' => 2],
            ],
        ])->assertCreated()
            ->assertJsonPath('subtotal', 300)
            ->assertJsonCount(2, 'items');
    }

    public function test_cantidad_fraccionaria_redondea_total_de_linea_a_dos_decimales(): void
    {
        $usuario = User::factory()->create(['rol' => Rol::Operario]);
        $cliente = Cliente::factory()->create();
        $producto = Producto::factory()->create(['precio_unitario' => 10.33]);

        $this->actingAs($usuario)->postJson('/api/v1/proformas', [
            'cliente_id' => $cliente->id,
            'fecha_emision' => '2026-10-23',
            'items' => [['producto_id' => $producto->id, 'cantidad' => 1.5]],
        ])->assertCreated()
            ->assertJsonPath('items.0.cantidad', 1.5)
            ->assertJsonPath('items.0.total_linea', 15.5)
            ->assertJsonPath('total', 15.5);
    }

    public function test_no_permite_productos_inactivos(): void
    {
        $usuario = User::factory()->create(['rol' => Rol::Operario]);
        $cliente = Cliente::factory()->create();
        $producto = Producto::factory()->inactivo()->create();

        $this->actingAs($usuario)->postJson('/api/v1/proformas', [
            'cliente_id' => $cliente->id,
            'fecha_emision' => '2026-10-23',
            'items' => [['producto_id' => $producto->id, 'cantidad' => 1]],
        ])->assertStatus(422)->assertJsonValidationErrors('items.0.producto_id');
    }

    public function test_no_permite_servicios_inactivos(): void
    {
        $usuario = User::factory()->create(['rol' => Rol::Operario]);
        $cliente = Cliente::factory()->create();
        $servicio = Servicio::factory()->inactivo()->create();

        $this->actingAs($usuario)->postJson('/api/v1/proformas', [
            'cliente_id' => $cliente->id,
            'fecha_emision' => '2026-10-23',
            'items' => [['tipo' => 'SERVICIO', 'servicio_id' => $servicio->id, 'cantidad' => 1]],
        ])->assertStatus(422)->assertJsonValidationErrors('items.0.servicio_id');
    }

    public function test_linea_libre_exige_descripcion_y_precio(): void
    {
        $usuario = User::factory()->create(['rol' => Rol::Operario]);
        $cliente = Cliente::factory()->create();

        $this->actingAs($usuario)->postJson('/api/v1/proformas', [
            'cliente_id' => $cliente->id,
            'fecha_emision' => '2026-10-23',
            'items' => [['tipo' => 'LIBRE', 'cantidad' => 1]],
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['items.0.descripcion', 'items.0.precio_unitario']);
    }

    public function test_material_sin_producto_es_422(): void
    {
        $usuario = User::factory()->create(['rol' => Rol::Operario]);
        $cliente = Cliente::factory()->create();

        $this->actingAs($usuario)->postJson('/api/v1/proformas', [
            'cliente_id' => $cliente->id,
            'fecha_emision' => '2026-10-23',
            'items' => [['tipo' => 'MATERIAL', 'cantidad' => 1]],
        ])->assertStatus(422)->assertJsonValidationErrors('items.0.producto_id');
    }

    public function test_linea_libre_admite_descripcion_larga(): void
    {
        $usuario = User::factory()->create(['rol' => Rol::Operario]);
        $cliente = Cliente::factory()->create();
        $descripcion = str_repeat('Detalle amplio de trabajo. ', 15);

        $this->actingAs($usuario)->postJson('/api/v1/proformas', [
            'cliente_id' => $cliente->id,
            'fecha_emision' => '2026-10-23',
            'items' => [[
                'tipo' => 'LIBRE',
                'descripcion' => $descripcion,
                'cantidad' => 1,
                'precio_unitario' => 5,
            ]],
        ])->assertCreated();

        $this->assertDatabaseHas('proforma_items', [
            'tipo' => 'LIBRE',
            'descripcion' => trim($descripcion),
        ]);
    }

    public function test_total_de_linea_no_puede_exceder_el_maximo_decimal(): void
    {
        $usuario = User::factory()->create(['rol' => Rol::Operario]);
        $cliente = Cliente::factory()->create();

        $this->actingAs($usuario)->postJson('/api/v1/proformas', [
            'cliente_id' => $cliente->id,
            'fecha_emision' => '2026-10-23',
            'items' => [[
                'tipo' => 'LIBRE',
                'descripcion' => 'Monto enorme',
                'cantidad' => 100000,
                'precio_unitario' => 9999999999.99,
            ]],
        ])->assertStatus(422)
            ->assertJsonValidationErrors('items.0.precio_unitario');
    }

    public function test_subtotal_no_puede_exceder_el_maximo_decimal(): void
    {
        $usuario = User::factory()->create(['rol' => Rol::Operario]);
        $cliente = Cliente::factory()->create();

        $this->actingAs($usuario)->postJson('/api/v1/proformas', [
            'cliente_id' => $cliente->id,
            'fecha_emision' => '2026-10-23',
            'items' => [
                ['tipo' => 'LIBRE', 'descripcion' => 'Línea 1', 'cantidad' => 1, 'precio_unitario' => 9999999999.99],
                ['tipo' => 'LIBRE', 'descripcion' => 'Línea 2', 'cantidad' => 1, 'precio_unitario' => 9999999999.99],
            ],
        ])->assertStatus(422)
            ->assertJsonValidationErrors('items');
    }

    public function test_update_convierte_linea_en_libre(): void
    {
        $usuario = User::factory()->create(['rol' => Rol::Operario]);
        $cliente = Cliente::factory()->create();
        $producto = Producto::factory()->create(['precio_unitario' => 100]);

        $proforma = $this->actingAs($usuario)->postJson('/api/v1/proformas', [
            'cliente_id' => $cliente->id,
            'fecha_emision' => '2026-10-23',
            'items' => [['producto_id' => $producto->id, 'cantidad' => 1]],
        ])->assertCreated()->json();

        $this->actingAs($usuario)->putJson("/api/v1/proformas/{$proforma['id']}", [
            'items' => [[
                'tipo' => 'LIBRE',
                'descripcion' => 'Ajuste manual',
                'cantidad' => 2,
                'precio_unitario' => 30,
            ]],
        ])->assertOk()
            ->assertJsonPath('subtotal', 60)
            ->assertJsonPath('items.0.tipo', 'LIBRE')
            ->assertJsonPath('items.0.producto_id', null)
            ->assertJsonPath('items.0.descripcion', 'Ajuste manual');
    }
}
