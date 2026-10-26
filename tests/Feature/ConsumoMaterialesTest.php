<?php

namespace Tests\Feature;

use App\Enums\EstadoOrden;
use App\Enums\Rol;
use App\Models\MovimientoInventario;
use App\Models\OrdenEvento;
use App\Models\OrdenItem;
use App\Models\OrdenServicio;
use App\Models\Producto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConsumoMaterialesTest extends TestCase
{
    use RefreshDatabase;

    public function test_consumo_total_descuenta_stock_registra_movimiento_y_evento(): void
    {
        $admin = $this->admin();
        $operario = $this->operario();
        $producto = Producto::factory()->create([
            'nombre' => 'Cable HDMI',
            'stock_actual' => 10,
            'costo_promedio' => 18,
        ]);
        $orden = $this->ordenEnProceso($operario, $producto, 4);

        $this->actingAs($admin)->postJson("/api/v1/ordenes/{$orden->id}/consumir")
            ->assertOk()
            ->assertJsonPath('estado', 'EN_PROCESO')
            ->assertJsonPath('items.0.cantidad_consumida', 4)
            ->assertJsonPath('items.0.cantidad_pendiente', 0)
            ->assertJsonPath('items.0.consumido', true)
            ->assertJsonPath('eventos.0.tipo', 'CONSUMO')
            ->assertJsonPath('eventos.0.estado_anterior', null)
            ->assertJsonPath('eventos.0.estado_nuevo', null)
            ->assertJsonPath('eventos.0.usuario.id', $admin->id)
            ->assertJsonPath('eventos.0.metadata.cantidad_lineas', 1)
            ->assertJsonPath('acciones_disponibles', fn ($acciones) => ! in_array('consumir', $acciones, true));

        $this->assertSame('6.000', $producto->fresh()->stock_actual);

        $item = $orden->items()->firstOrFail();
        $this->assertSame('4.000', $item->cantidad_consumida);
        $this->assertNotNull($item->consumido_at);

        $this->assertDatabaseHas('movimientos_inventario', [
            'producto_id' => $producto->id,
            'orden_id' => $orden->id,
            'orden_item_id' => $item->id,
            'tipo' => 'CONSUMO',
            'cantidad' => -4,
            'stock_anterior' => 10,
            'stock_nuevo' => 6,
            'costo_unitario' => 18,
            'motivo' => "Consumo de materiales de la orden {$orden->numero}",
            'created_by' => $admin->id,
        ]);
    }

    public function test_consumo_parcial_se_acumula_y_solo_marca_consumido_al_completar(): void
    {
        $admin = $this->admin();
        $operario = $this->operario();
        $producto = Producto::factory()->create([
            'stock_actual' => 30,
            'costo_promedio' => 10,
        ]);
        $orden = $this->ordenEnProceso($operario, $producto, 10);
        $item = $orden->items()->firstOrFail();

        $this->actingAs($admin)->postJson("/api/v1/ordenes/{$orden->id}/consumir", [
            'items' => [['orden_item_id' => $item->id, 'cantidad' => 4]],
        ])
            ->assertOk()
            ->assertJsonPath('items.0.cantidad_consumida', 4)
            ->assertJsonPath('items.0.cantidad_pendiente', 6)
            ->assertJsonPath('items.0.consumido', false)
            ->assertJsonPath('items.0.consumido_at', null)
            ->assertJsonPath('acciones_disponibles', fn ($acciones) => in_array('consumir', $acciones, true));

        $this->assertSame('26.000', $producto->fresh()->stock_actual);
        $this->assertNull($item->fresh()->consumido_at);

        $this->actingAs($admin)->postJson("/api/v1/ordenes/{$orden->id}/consumir", [
            'items' => [['orden_item_id' => $item->id, 'cantidad' => 6]],
        ])
            ->assertOk()
            ->assertJsonPath('items.0.cantidad_consumida', 10)
            ->assertJsonPath('items.0.cantidad_pendiente', 0)
            ->assertJsonPath('items.0.consumido', true);

        $this->assertSame('20.000', $producto->fresh()->stock_actual);
        $this->assertNotNull($item->fresh()->consumido_at);
        $this->assertSame(2, MovimientoInventario::query()->where('tipo', 'CONSUMO')->count());
        $this->assertSame(2, $orden->fresh()->eventos()->where('tipo', 'CONSUMO')->count());
    }

    public function test_consume_multiples_materiales_de_varios_productos(): void
    {
        $admin = $this->admin();
        $operario = $this->operario();
        $productoA = Producto::factory()->create(['stock_actual' => 10, 'costo_promedio' => 5]);
        $productoB = Producto::factory()->create(['stock_actual' => 5, 'costo_promedio' => 7]);

        $orden = OrdenServicio::factory()->enProceso()->create(['operario_id' => $operario->id]);
        $itemA = OrdenItem::factory()->create([
            'orden_id' => $orden->id,
            'producto_id' => $productoA->id,
            'cantidad' => 3,
        ]);
        $itemB = OrdenItem::factory()->create([
            'orden_id' => $orden->id,
            'producto_id' => $productoB->id,
            'cantidad' => 2,
        ]);

        $response = $this->actingAs($admin)->postJson("/api/v1/ordenes/{$orden->id}/consumir")
            ->assertOk()
            ->assertJsonPath('eventos.0.metadata.cantidad_lineas', 2)
            ->assertJsonPath('acciones_disponibles', fn ($acciones) => ! in_array('consumir', $acciones, true));

        $items = collect($response->json('items'))->keyBy('id');
        $this->assertTrue($items[$itemA->id]['consumido']);
        $this->assertTrue($items[$itemB->id]['consumido']);

        $this->assertSame('7.000', $productoA->fresh()->stock_actual);
        $this->assertSame('3.000', $productoB->fresh()->stock_actual);
        $this->assertSame(2, MovimientoInventario::query()->where('orden_id', $orden->id)->count());

        foreach ([$itemA->id, $itemB->id] as $itemId) {
            $this->assertDatabaseHas('movimientos_inventario', [
                'orden_id' => $orden->id,
                'orden_item_id' => $itemId,
                'tipo' => 'CONSUMO',
            ]);
        }

        $this->assertSame(1, OrdenEvento::query()->where('orden_id', $orden->id)->count());
    }

    public function test_consumo_explicito_por_linea_y_productos_se_bloquean_en_orden_ascendente(): void
    {
        $admin = $this->admin();
        $operario = $this->operario();
        $productoA = Producto::factory()->create(['stock_actual' => 10]);
        $productoB = Producto::factory()->create(['stock_actual' => 10]);

        $orden = OrdenServicio::factory()->enProceso()->create(['operario_id' => $operario->id]);
        $itemB = OrdenItem::factory()->create([
            'orden_id' => $orden->id,
            'producto_id' => $productoB->id,
            'cantidad' => 5,
        ]);
        $itemA = OrdenItem::factory()->create([
            'orden_id' => $orden->id,
            'producto_id' => $productoA->id,
            'cantidad' => 5,
        ]);

        $this->actingAs($admin)->postJson("/api/v1/ordenes/{$orden->id}/consumir", [
            'items' => [
                ['orden_item_id' => $itemB->id, 'cantidad' => 1],
                ['orden_item_id' => $itemA->id, 'cantidad' => 2],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('eventos.0.metadata.cantidad_lineas', 2);

        $this->assertSame('8.000', $productoA->fresh()->stock_actual);
        $this->assertSame('9.000', $productoB->fresh()->stock_actual);
        $this->assertSame('2.000', $itemA->fresh()->cantidad_consumida);
        $this->assertSame('1.000', $itemB->fresh()->cantidad_consumida);
    }

    public function test_rechaza_exceso_duplicados_cantidad_cero_y_stock_insuficiente(): void
    {
        $admin = $this->admin();
        $operario = $this->operario();
        $producto = Producto::factory()->create(['stock_actual' => 2]);
        $orden = $this->ordenEnProceso($operario, $producto, 10);
        $item = $orden->items()->firstOrFail();

        $this->actingAs($admin)->postJson("/api/v1/ordenes/{$orden->id}/consumir", [
            'items' => [['orden_item_id' => $item->id, 'cantidad' => 11]],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('items.0.cantidad')
            ->assertJsonPath('message', fn ($message) => str_contains($message, 'supera el pendiente'));

        $this->actingAs($admin)->postJson("/api/v1/ordenes/{$orden->id}/consumir", [
            'items' => [['orden_item_id' => $item->id, 'cantidad' => 3]],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('items')
            ->assertJsonPath('message', fn ($message) => str_contains($message, 'Stock insuficiente'));

        $this->actingAs($admin)->postJson("/api/v1/ordenes/{$orden->id}/consumir", [
            'items' => [
                ['orden_item_id' => $item->id, 'cantidad' => 1],
                ['orden_item_id' => $item->id, 'cantidad' => 1],
            ],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('items.1.orden_item_id');

        $this->actingAs($admin)->postJson("/api/v1/ordenes/{$orden->id}/consumir", [
            'items' => [['orden_item_id' => $item->id, 'cantidad' => 0]],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('items.0.cantidad');

        $this->assertDatabaseCount('movimientos_inventario', 0);
        $this->assertSame('2.000', $producto->fresh()->stock_actual);
        $this->assertSame('0.000', $item->fresh()->cantidad_consumida);
    }

    public function test_no_consume_lineas_de_servicio_libre_ni_orden_sin_pendientes(): void
    {
        $admin = $this->admin();
        $operario = $this->operario();
        $producto = Producto::factory()->create(['stock_actual' => 10]);

        $orden = OrdenServicio::factory()->enProceso()->create(['operario_id' => $operario->id]);
        $material = OrdenItem::factory()->create([
            'orden_id' => $orden->id,
            'producto_id' => $producto->id,
            'cantidad' => 1,
        ]);
        $servicio = OrdenItem::factory()->servicio()->create(['orden_id' => $orden->id]);
        $libre = OrdenItem::factory()->libre()->create(['orden_id' => $orden->id]);

        foreach ([$servicio->id, $libre->id] as $itemId) {
            $this->actingAs($admin)->postJson("/api/v1/ordenes/{$orden->id}/consumir", [
                'items' => [['orden_item_id' => $itemId, 'cantidad' => 1]],
            ])
                ->assertStatus(422)
                ->assertJsonValidationErrors('items.0.orden_item_id');
        }

        $this->actingAs($admin)->postJson("/api/v1/ordenes/{$orden->id}/consumir", [
            'items' => [['orden_item_id' => $material->id, 'cantidad' => 1]],
        ])->assertOk();

        $this->actingAs($admin)->postJson("/api/v1/ordenes/{$orden->id}/consumir")
            ->assertStatus(422)
            ->assertJsonValidationErrors('items');

        $this->actingAs($admin)->postJson("/api/v1/ordenes/{$orden->id}/consumir", [
            'items' => [['orden_item_id' => $material->id, 'cantidad' => 1]],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('items.0.orden_item_id')
            ->assertJsonPath('message', fn ($message) => str_contains($message, 'consumida por completo'));

        $this->assertDatabaseCount('movimientos_inventario', 1);
        $this->assertSame('9.000', $producto->fresh()->stock_actual);
    }

    public function test_rechaza_orden_cancelada_cerrada_pendiente_o_asignada(): void
    {
        $admin = $this->admin();
        $operario = $this->operario();
        $producto = Producto::factory()->create(['stock_actual' => 100]);

        $cancelada = $this->ordenConEstado($operario, $producto, EstadoOrden::Cancelada);
        $cerrada = $this->ordenConEstado($operario, $producto, EstadoOrden::Finalizada);
        $cerrada->update(['cerrada_at' => now()]);
        $pendiente = $this->ordenConEstado($operario, $producto, EstadoOrden::Pendiente);
        $asignada = $this->ordenConEstado($operario, $producto, EstadoOrden::Asignada);

        foreach ([$cancelada, $cerrada, $pendiente, $asignada] as $orden) {
            $this->actingAs($admin)->postJson("/api/v1/ordenes/{$orden->id}/consumir")
                ->assertStatus(422)
                ->assertJsonValidationErrors('estado');
        }

        $this->assertDatabaseCount('movimientos_inventario', 0);
        $this->assertSame('100.000', $producto->fresh()->stock_actual);
    }

    public function test_orden_finalizada_no_cerrada_permite_consumo(): void
    {
        $admin = $this->admin();
        $operario = $this->operario();
        $producto = Producto::factory()->create(['stock_actual' => 10]);
        $orden = $this->ordenConEstado($operario, $producto, EstadoOrden::Finalizada);

        $this->actingAs($admin)->postJson("/api/v1/ordenes/{$orden->id}/consumir")
            ->assertOk()
            ->assertJsonPath('estado', 'FINALIZADA')
            ->assertJsonPath('items.0.consumido', true);

        $this->assertSame('9.000', $producto->fresh()->stock_actual);
    }

    public function test_operario_ajeno_recibe_403_y_el_asignado_o_admin_pueden_consumir(): void
    {
        $admin = $this->admin();
        $operarioA = $this->operario();
        $operarioB = $this->operario();
        $producto = Producto::factory()->create(['stock_actual' => 10]);

        $ordenA = $this->ordenEnProceso($operarioA, $producto, 2);
        $ordenB = $this->ordenEnProceso($operarioB, $producto, 2);

        $this->actingAs($operarioB)->postJson("/api/v1/ordenes/{$ordenA->id}/consumir")
            ->assertForbidden();

        $this->actingAs($operarioA)->postJson("/api/v1/ordenes/{$ordenA->id}/consumir")
            ->assertOk()
            ->assertJsonPath('items.0.consumido', true);

        $this->actingAs($admin)->postJson("/api/v1/ordenes/{$ordenB->id}/consumir")
            ->assertOk();

        $this->assertSame('6.000', $producto->fresh()->stock_actual);
        $this->assertSame(2, MovimientoInventario::query()->where('tipo', 'CONSUMO')->count());
    }

    public function test_detalle_de_orden_expone_campos_de_consumo_y_accion_disponible(): void
    {
        $admin = $this->admin();
        $operario = $this->operario();
        $producto = Producto::factory()->create(['stock_actual' => 10]);
        $orden = $this->ordenEnProceso($operario, $producto, 5);
        $item = $orden->items()->firstOrFail();

        $this->actingAs($operario)->getJson("/api/v1/ordenes/{$orden->id}")
            ->assertOk()
            ->assertJsonPath('items.0.cantidad_consumida', 0)
            ->assertJsonPath('items.0.cantidad_pendiente', 5)
            ->assertJsonPath('items.0.consumido', false)
            ->assertJsonPath('items.0.consumido_at', null)
            ->assertJsonPath('acciones_disponibles', fn ($acciones) => in_array('consumir', $acciones, true));

        $this->actingAs($operario)->postJson("/api/v1/ordenes/{$orden->id}/consumir", [
            'items' => [['orden_item_id' => $item->id, 'cantidad' => 2]],
        ])
            ->assertOk()
            ->assertJsonPath('items.0.cantidad_consumida', 2)
            ->assertJsonPath('items.0.cantidad_pendiente', 3)
            ->assertJsonPath('items.0.consumido', false)
            ->assertJsonPath('items.0.consumido_at', null);

        $this->actingAs($operario)->getJson("/api/v1/ordenes/{$orden->id}")
            ->assertOk()
            ->assertJsonPath('items.0.cantidad_consumida', 2)
            ->assertJsonPath('items.0.cantidad_pendiente', 3)
            ->assertJsonPath('items.0.consumido', false)
            ->assertJsonPath('acciones_disponibles', fn ($acciones) => in_array('consumir', $acciones, true));
    }

    public function test_movimientos_filtran_por_orden_y_consumo(): void
    {
        $admin = $this->admin();
        $operario = $this->operario();
        $producto = Producto::factory()->create(['stock_actual' => 10]);
        $orden = $this->ordenEnProceso($operario, $producto, 2);

        $this->actingAs($admin)->postJson("/api/v1/ordenes/{$orden->id}/consumir")->assertOk();

        $this->actingAs($admin)->getJson('/api/v1/inventario/movimientos?orden_id='.$orden->id)
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.tipo', 'CONSUMO')
            ->assertJsonPath('data.0.orden.id', $orden->id)
            ->assertJsonPath('data.0.orden.numero', $orden->numero);

        $this->actingAs($admin)->getJson('/api/v1/inventario/movimientos?search='.urlencode($orden->numero))
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.orden_id', $orden->id);
    }

    private function admin(): User
    {
        return User::factory()->create(['rol' => Rol::Admin]);
    }

    private function operario(): User
    {
        return User::factory()->create(['rol' => Rol::Operario, 'activo' => true]);
    }

    private function ordenEnProceso(User $operario, Producto $producto, int $cantidad): OrdenServicio
    {
        return $this->ordenConEstado($operario, $producto, EstadoOrden::EnProceso, $cantidad);
    }

    private function ordenConEstado(
        User $operario,
        Producto $producto,
        EstadoOrden $estado,
        int $cantidad = 1,
    ): OrdenServicio {
        $orden = OrdenServicio::factory()->create([
            'operario_id' => $operario->id,
            'estado' => $estado,
        ]);

        OrdenItem::factory()->create([
            'orden_id' => $orden->id,
            'producto_id' => $producto->id,
            'cantidad' => $cantidad,
        ]);

        return $orden;
    }
}
