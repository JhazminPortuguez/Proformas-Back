<?php

namespace Tests\Feature;

use App\Enums\Rol;
use App\Models\Cliente;
use App\Models\Producto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProformaTest extends TestCase
{
    use RefreshDatabase;

    public function test_crear_proforma_calcula_totales_con_precio_del_producto(): void
    {
        $operario = User::factory()->create(['rol' => Rol::Operario]);
        $cliente = Cliente::factory()->create();
        $producto = Producto::factory()->create([
            'precio_unitario' => 100,
            'nombre' => 'Producto de prueba',
            'unidad_medida' => 'unidad',
        ]);

        $response = $this->actingAs($operario)->postJson('/api/v1/proformas', [
            'cliente_id' => $cliente->id,
            'fecha_emision' => '2026-10-23',
            'moneda' => 'bob',
            'descuento' => 10,
            'impuesto_tasa' => 13,
            'items' => [
                [
                    'producto_id' => $producto->id,
                    'cantidad' => 2,
                    'precio_unitario' => 1, // debe ignorarse para materiales
                ],
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('numero', 'PR-2026-000001')
            ->assertJsonPath('estado', 'BORRADOR')
            ->assertJsonPath('moneda', 'BOB')
            ->assertJsonPath('impuesto_tasa', 13)
            ->assertJsonPath('subtotal', 200)
            ->assertJsonPath('descuento', 10)
            ->assertJsonPath('impuesto', 24.7)
            ->assertJsonPath('total', 214.7)
            ->assertJsonPath('origen', 'INTERNA')
            ->assertJsonPath('created_by', $operario->id)
            ->assertJsonPath('items.0.tipo', 'MATERIAL')
            ->assertJsonPath('items.0.nombre', 'Producto de prueba')
            ->assertJsonPath('items.0.unidad_medida', 'unidad');

        $this->assertDatabaseHas('proforma_items', [
            'producto_id' => $producto->id,
            'cantidad' => 2,
            'precio_unitario' => 100,
            'total_linea' => 200,
        ]);
    }

    public function test_correlativo_es_secuencial_por_anio(): void
    {
        $usuario = User::factory()->create(['rol' => Rol::Operario]);
        $cliente = Cliente::factory()->create();
        $producto = Producto::factory()->create(['precio_unitario' => 50]);

        $payload = [
            'cliente_id' => $cliente->id,
            'fecha_emision' => '2026-10-23',
            'items' => [['producto_id' => $producto->id, 'cantidad' => 1]],
        ];

        $this->actingAs($usuario)->postJson('/api/v1/proformas', $payload)
            ->assertCreated()
            ->assertJsonPath('numero', 'PR-2026-000001');

        $this->actingAs($usuario)->postJson('/api/v1/proformas', $payload)
            ->assertCreated()
            ->assertJsonPath('numero', 'PR-2026-000002');
    }

    public function test_descuento_no_puede_superar_subtotal(): void
    {
        $usuario = User::factory()->create(['rol' => Rol::Operario]);
        $cliente = Cliente::factory()->create();
        $producto = Producto::factory()->create(['precio_unitario' => 10]);

        $this->actingAs($usuario)->postJson('/api/v1/proformas', [
            'cliente_id' => $cliente->id,
            'fecha_emision' => '2026-10-23',
            'descuento' => 500,
            'items' => [['producto_id' => $producto->id, 'cantidad' => 1]],
        ])->assertStatus(422)->assertJsonValidationErrors('descuento');
    }

    public function test_impuesto_tasa_fuera_de_rango_es_422(): void
    {
        $usuario = User::factory()->create(['rol' => Rol::Operario]);
        $cliente = Cliente::factory()->create();
        $producto = Producto::factory()->create(['precio_unitario' => 10]);

        $this->actingAs($usuario)->postJson('/api/v1/proformas', [
            'cliente_id' => $cliente->id,
            'fecha_emision' => '2026-10-23',
            'impuesto_tasa' => 101,
            'items' => [['producto_id' => $producto->id, 'cantidad' => 1]],
        ])->assertStatus(422)->assertJsonValidationErrors('impuesto_tasa');
    }

    public function test_fecha_validez_no_puede_ser_anterior_a_la_emision(): void
    {
        $usuario = User::factory()->create(['rol' => Rol::Operario]);
        $cliente = Cliente::factory()->create();
        $producto = Producto::factory()->create(['precio_unitario' => 10]);

        $this->actingAs($usuario)->postJson('/api/v1/proformas', [
            'cliente_id' => $cliente->id,
            'fecha_emision' => '2026-10-23',
            'fecha_validez' => '2026-10-22',
            'items' => [['producto_id' => $producto->id, 'cantidad' => 1]],
        ])->assertStatus(422)->assertJsonValidationErrors('fecha_validez');
    }

    public function test_cantidad_debe_ser_mayor_que_cero(): void
    {
        $usuario = User::factory()->create(['rol' => Rol::Operario]);
        $cliente = Cliente::factory()->create();
        $producto = Producto::factory()->create(['precio_unitario' => 10]);

        $this->actingAs($usuario)->postJson('/api/v1/proformas', [
            'cliente_id' => $cliente->id,
            'fecha_emision' => '2026-10-23',
            'items' => [['producto_id' => $producto->id, 'cantidad' => 0]],
        ])->assertStatus(422)->assertJsonValidationErrors('items.0.cantidad');
    }

    public function test_update_recalcula_totales(): void
    {
        [$usuario, $proforma, $producto] = $this->crearProforma(100, 2);

        $this->actingAs($usuario)->putJson("/api/v1/proformas/{$proforma['id']}", [
            'descuento' => 20,
            'items' => [['producto_id' => $producto->id, 'cantidad' => 3]],
        ])
            ->assertOk()
            ->assertJsonPath('subtotal', 300)
            ->assertJsonPath('descuento', 20)
            ->assertJsonPath('total', 280);
    }

    public function test_put_generico_no_permite_cambiar_estado(): void
    {
        [$usuario, $proforma] = $this->crearProforma(100, 1);

        $this->actingAs($usuario)->putJson("/api/v1/proformas/{$proforma['id']}", [
            'estado' => 'ACEPTADA',
        ])->assertStatus(422)->assertJsonValidationErrors('estado');
    }

    public function test_no_se_edita_contenido_de_proforma_enviada(): void
    {
        [$usuario, $proforma, $producto] = $this->crearProforma(100, 1);

        $this->actingAs($usuario)->postJson("/api/v1/proformas/{$proforma['id']}/enviar")
            ->assertOk()
            ->assertJsonPath('estado', 'ENVIADA')
            ->assertJsonPath('enviada_at', fn ($fecha) => $fecha !== null);

        $this->actingAs($usuario)->putJson("/api/v1/proformas/{$proforma['id']}", [
            'items' => [['producto_id' => $producto->id, 'cantidad' => 5]],
        ])->assertStatus(422)->assertJsonValidationErrors('estado');
    }

    public function test_flujo_enviar_aceptar_registra_auditoria(): void
    {
        [$usuario, $proforma] = $this->crearProforma(100, 1);

        $this->actingAs($usuario)->postJson("/api/v1/proformas/{$proforma['id']}/enviar")
            ->assertOk()
            ->assertJsonPath('estado', 'ENVIADA');

        $this->actingAs($usuario)->postJson("/api/v1/proformas/{$proforma['id']}/aceptar")
            ->assertOk()
            ->assertJsonPath('estado', 'ACEPTADA')
            ->assertJsonPath('aceptada_por', $usuario->id)
            ->assertJsonPath('aceptada_at', fn ($fecha) => $fecha !== null);

        $this->assertDatabaseHas('proformas', [
            'id' => $proforma['id'],
            'estado' => 'ACEPTADA',
            'aceptada_por' => $usuario->id,
        ]);
    }

    public function test_segunda_aceptacion_es_rechazada(): void
    {
        [$usuario, $proforma] = $this->crearProforma(100, 1);

        $this->actingAs($usuario)->postJson("/api/v1/proformas/{$proforma['id']}/enviar")->assertOk();
        $this->actingAs($usuario)->postJson("/api/v1/proformas/{$proforma['id']}/aceptar")->assertOk();

        $this->actingAs($usuario)->postJson("/api/v1/proformas/{$proforma['id']}/aceptar")
            ->assertStatus(422)
            ->assertJsonValidationErrors('estado');
    }

    public function test_rechazar_exige_motivo_y_registra_fecha(): void
    {
        [$usuario, $proforma] = $this->crearProforma(100, 1);

        $this->actingAs($usuario)->postJson("/api/v1/proformas/{$proforma['id']}/enviar")->assertOk();

        $this->actingAs($usuario)->postJson("/api/v1/proformas/{$proforma['id']}/rechazar", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('motivo');

        $this->actingAs($usuario)->postJson("/api/v1/proformas/{$proforma['id']}/rechazar", [
            'motivo' => 'El cliente eligió otra propuesta.',
        ])
            ->assertOk()
            ->assertJsonPath('estado', 'RECHAZADA')
            ->assertJsonPath('motivo_rechazo', 'El cliente eligió otra propuesta.')
            ->assertJsonPath('rechazada_at', fn ($fecha) => $fecha !== null);
    }

    public function test_no_se_puede_enviar_una_proforma_ya_aceptada(): void
    {
        [$usuario, $proforma] = $this->crearProforma(100, 1);

        $this->actingAs($usuario)->postJson("/api/v1/proformas/{$proforma['id']}/enviar")->assertOk();
        $this->actingAs($usuario)->postJson("/api/v1/proformas/{$proforma['id']}/aceptar")->assertOk();

        $this->actingAs($usuario)->postJson("/api/v1/proformas/{$proforma['id']}/enviar")
            ->assertStatus(422)
            ->assertJsonValidationErrors('estado');
    }

    public function test_index_pagina_y_filtra_por_estado(): void
    {
        [$usuario] = $this->crearProforma(100, 1);

        $response = $this->actingAs($usuario)->getJson('/api/v1/proformas?estado=BORRADOR&per_page=5');

        $response->assertOk()
            ->assertJsonStructure(['data', 'meta' => ['current_page', 'per_page', 'total', 'last_page']])
            ->assertJsonPath('meta.total', 1);
    }

    /**
     * @return array{0: User, 1: array<string, mixed>, 2: Producto}
     */
    private function crearProforma(float $precio, int $cantidad): array
    {
        $usuario = User::factory()->create(['rol' => Rol::Operario]);
        $cliente = Cliente::factory()->create();
        $producto = Producto::factory()->create(['precio_unitario' => $precio]);

        $proforma = $this->actingAs($usuario)->postJson('/api/v1/proformas', [
            'cliente_id' => $cliente->id,
            'fecha_emision' => '2026-10-23',
            'items' => [['producto_id' => $producto->id, 'cantidad' => $cantidad]],
        ])->assertCreated()->json();

        return [$usuario, $proforma, $producto];
    }
}
