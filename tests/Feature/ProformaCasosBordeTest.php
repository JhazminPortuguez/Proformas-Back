<?php

namespace Tests\Feature;

use App\Actions\Proformas\CrearProforma;
use App\Enums\EstadoCliente;
use App\Enums\EstadoSolicitud;
use App\Enums\Rol;
use App\Models\Cliente;
use App\Models\Producto;
use App\Models\Proforma;
use App\Models\Servicio;
use App\Models\SolicitudCotizacion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProformaCasosBordeTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_se_crean_proformas_para_un_cliente_inactivo(): void
    {
        $usuario = User::factory()->create(['rol' => Rol::Operario]);
        $inactivo = Cliente::factory()->inactivo()->create();
        $producto = Producto::factory()->create(['precio_unitario' => 10]);

        $this->actingAs($usuario)->postJson('/api/v1/proformas', [
            'cliente_id' => $inactivo->id,
            'fecha_emision' => '2026-11-08',
            'items' => [['producto_id' => $producto->id, 'cantidad' => 1]],
        ])->assertStatus(422)
            ->assertJsonPath('errors.cliente_id.0', CrearProforma::MENSAJE_CLIENTE_INACTIVO);

        $this->assertSame(0, Proforma::query()->count());

        // Prospecto y Activo sí.
        foreach ([Cliente::factory()->prospecto()->create(), Cliente::factory()->create()] as $cliente) {
            $this->actingAs($usuario)->postJson('/api/v1/proformas', [
                'cliente_id' => $cliente->id,
                'fecha_emision' => '2026-11-08',
                'items' => [['producto_id' => $producto->id, 'cantidad' => 1]],
            ])->assertCreated();
        }
    }

    public function test_no_se_convierte_una_solicitud_de_un_cliente_inactivo(): void
    {
        $usuario = User::factory()->create(['rol' => Rol::Operario]);
        $cliente = Cliente::factory()->inactivo()->create();
        $solicitud = SolicitudCotizacion::factory()->create([
            'cliente_id' => $cliente->id,
            'descripcion' => 'Revisión de impresora',
        ]);

        $this->actingAs($usuario)->postJson("/api/v1/solicitudes/{$solicitud->id}/convertir")
            ->assertStatus(422)
            ->assertJsonPath('errors.cliente_id.0', CrearProforma::MENSAJE_CLIENTE_INACTIVO);

        $this->assertSame(EstadoSolicitud::Pendiente, $solicitud->fresh()->estado);
        $this->assertSame(0, Proforma::query()->count());
    }

    public function test_borrador_de_un_cliente_que_se_desactivo_se_puede_editar_pero_no_pasar_a_otro_inactivo(): void
    {
        $usuario = User::factory()->create(['rol' => Rol::Operario]);
        $cliente = Cliente::factory()->create();
        $producto = Producto::factory()->create(['precio_unitario' => 10]);
        $proforma = $this->crearBorrador($usuario, $cliente, [['producto_id' => $producto->id, 'cantidad' => 1]]);

        $cliente->update(['estado' => EstadoCliente::Inactivo]);

        $this->actingAs($usuario)->putJson("/api/v1/proformas/{$proforma['id']}", [
            'cliente_id' => $cliente->id,
            'observaciones' => 'Sigue siendo del mismo cliente.',
        ])->assertOk();

        $otroInactivo = Cliente::factory()->inactivo()->create();

        $this->actingAs($usuario)->putJson("/api/v1/proformas/{$proforma['id']}", [
            'cliente_id' => $otroInactivo->id,
        ])->assertStatus(422)
            ->assertJsonPath('errors.cliente_id.0', 'No se puede pasar la proforma a un cliente inactivo.');
    }

    public function test_items_nuevos_solo_con_servicios_y_productos_activos(): void
    {
        $usuario = User::factory()->create(['rol' => Rol::Operario]);
        $cliente = Cliente::factory()->create();
        $productoInactivo = Producto::factory()->inactivo()->create();
        $servicioInactivo = Servicio::factory()->inactivo()->create();

        $this->actingAs($usuario)->postJson('/api/v1/proformas', [
            'cliente_id' => $cliente->id,
            'fecha_emision' => '2026-11-08',
            'items' => [['tipo' => 'MATERIAL', 'producto_id' => $productoInactivo->id, 'cantidad' => 1]],
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['items.0.producto_id' => 'El producto seleccionado no está activo.']);

        $this->actingAs($usuario)->postJson('/api/v1/proformas', [
            'cliente_id' => $cliente->id,
            'fecha_emision' => '2026-11-08',
            'items' => [['tipo' => 'SERVICIO', 'servicio_id' => $servicioInactivo->id, 'cantidad' => 1]],
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['items.0.servicio_id' => 'El servicio seleccionado no está activo.']);
    }

    public function test_proforma_que_ya_tiene_catalogo_desactivado_se_sigue_editando_sin_cambiarlo(): void
    {
        $usuario = User::factory()->create(['rol' => Rol::Operario]);
        $cliente = Cliente::factory()->create();
        $producto = Producto::factory()->create(['precio_unitario' => 80]);
        $servicio = Servicio::factory()->create(['precio_base' => 120]);
        $proforma = $this->crearBorrador($usuario, $cliente, [
            ['tipo' => 'MATERIAL', 'producto_id' => $producto->id, 'cantidad' => 2],
            ['tipo' => 'SERVICIO', 'servicio_id' => $servicio->id, 'cantidad' => 1],
        ]);

        $producto->update(['activo' => false]);
        $servicio->update(['activo' => false]);

        // Mismas líneas, otra cantidad: se acepta.
        $this->actingAs($usuario)->putJson("/api/v1/proformas/{$proforma['id']}", [
            'items' => [
                ['tipo' => 'MATERIAL', 'producto_id' => $producto->id, 'cantidad' => 3],
                ['tipo' => 'SERVICIO', 'servicio_id' => $servicio->id, 'cantidad' => 1],
            ],
        ])->assertOk()
            ->assertJsonPath('items.0.producto_id', $producto->id)
            ->assertJsonPath('items.1.servicio_id', $servicio->id)
            ->assertJsonPath('subtotal', 360);

        // Un producto inactivo que no estaba en la proforma, no.
        $otro = Producto::factory()->inactivo()->create();

        $this->actingAs($usuario)->putJson("/api/v1/proformas/{$proforma['id']}", [
            'items' => [
                ['tipo' => 'MATERIAL', 'producto_id' => $producto->id, 'cantidad' => 3],
                ['tipo' => 'MATERIAL', 'producto_id' => $otro->id, 'cantidad' => 1],
            ],
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['items.1.producto_id' => 'El producto seleccionado no está activo.']);
    }

    public function test_prospecto_pasa_a_activo_al_aceptar_su_primera_proforma(): void
    {
        $usuario = User::factory()->create(['rol' => Rol::Operario]);
        $prospecto = Cliente::factory()->prospecto()->create();
        $producto = Producto::factory()->create(['precio_unitario' => 10]);
        $proforma = $this->crearBorrador($usuario, $prospecto, [['producto_id' => $producto->id, 'cantidad' => 1]]);

        $this->actingAs($usuario)->postJson("/api/v1/proformas/{$proforma['id']}/enviar")->assertOk();

        // Enviar no lo cambia: solo aceptar.
        $this->assertSame(EstadoCliente::Prospecto, $prospecto->fresh()->estado);

        $this->actingAs($usuario)->postJson("/api/v1/proformas/{$proforma['id']}/aceptar")
            ->assertOk()
            ->assertJsonPath('cliente.estado', 'activo');

        $this->assertSame(EstadoCliente::Activo, $prospecto->fresh()->estado);
    }

    public function test_aceptar_no_reactiva_a_un_cliente_inactivo(): void
    {
        $usuario = User::factory()->create(['rol' => Rol::Operario]);
        $cliente = Cliente::factory()->create();
        $producto = Producto::factory()->create(['precio_unitario' => 10]);
        $proforma = $this->crearBorrador($usuario, $cliente, [['producto_id' => $producto->id, 'cantidad' => 1]]);
        $this->actingAs($usuario)->postJson("/api/v1/proformas/{$proforma['id']}/enviar")->assertOk();

        $cliente->update(['estado' => EstadoCliente::Inactivo]);

        $this->actingAs($usuario)->postJson("/api/v1/proformas/{$proforma['id']}/aceptar")->assertOk();

        $this->assertSame(EstadoCliente::Inactivo, $cliente->fresh()->estado);
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    private function crearBorrador(User $usuario, Cliente $cliente, array $items): array
    {
        return $this->actingAs($usuario)->postJson('/api/v1/proformas', [
            'cliente_id' => $cliente->id,
            'fecha_emision' => '2026-11-08',
            'items' => $items,
        ])->assertCreated()->json();
    }
}
