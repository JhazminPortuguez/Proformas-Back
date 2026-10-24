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
        $producto = Producto::factory()->create(['precio_unitario' => 100]);

        $response = $this->actingAs($operario)->postJson('/api/v1/proformas', [
            'cliente_id' => $cliente->id,
            'fecha_emision' => '2026-10-23',
            'descuento' => 10,
            'impuesto' => 5,
            'items' => [
                [
                    'producto_id' => $producto->id,
                    'cantidad' => 2,
                    'precio_unitario' => 1, // debe ignorarse
                ],
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('numero', 'PR-2026-000001')
            ->assertJsonPath('estado', 'BORRADOR')
            ->assertJsonPath('subtotal', 200)
            ->assertJsonPath('descuento', 10)
            ->assertJsonPath('impuesto', 5)
            ->assertJsonPath('total', 195)
            ->assertJsonPath('created_by', $operario->id);

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

    public function test_transicion_invalida_de_estado_es_422(): void
    {
        [$usuario, $proforma] = $this->crearProforma(100, 1);

        $this->actingAs($usuario)->putJson("/api/v1/proformas/{$proforma['id']}", [
            'estado' => 'ACEPTADA',
        ])->assertStatus(422)->assertJsonValidationErrors('estado');
    }

    public function test_no_se_edita_contenido_de_proforma_enviada(): void
    {
        [$usuario, $proforma, $producto] = $this->crearProforma(100, 1);

        $this->actingAs($usuario)->putJson("/api/v1/proformas/{$proforma['id']}", [
            'estado' => 'ENVIADA',
        ])->assertOk()->assertJsonPath('estado', 'ENVIADA');

        $this->actingAs($usuario)->putJson("/api/v1/proformas/{$proforma['id']}", [
            'items' => [['producto_id' => $producto->id, 'cantidad' => 5]],
        ])->assertStatus(422)->assertJsonValidationErrors('estado');

        $this->actingAs($usuario)->putJson("/api/v1/proformas/{$proforma['id']}", [
            'estado' => 'ACEPTADA',
        ])->assertOk()->assertJsonPath('estado', 'ACEPTADA');
    }

    public function test_proforma_aceptada_no_vuelve_atras(): void
    {
        [$usuario, $proforma] = $this->crearProforma(100, 1);

        $this->actingAs($usuario)->putJson("/api/v1/proformas/{$proforma['id']}", ['estado' => 'ENVIADA'])
            ->assertOk();
        $this->actingAs($usuario)->putJson("/api/v1/proformas/{$proforma['id']}", ['estado' => 'ACEPTADA'])
            ->assertOk();

        $this->actingAs($usuario)->putJson("/api/v1/proformas/{$proforma['id']}", ['estado' => 'BORRADOR'])
            ->assertStatus(422);
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
