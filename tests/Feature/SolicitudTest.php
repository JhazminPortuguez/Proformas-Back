<?php

namespace Tests\Feature;

use App\Enums\Rol;
use App\Models\Cliente;
use App\Models\SolicitudCotizacion;
use App\Models\SolicitudItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SolicitudTest extends TestCase
{
    use RefreshDatabase;

    public function test_operario_crea_solicitud_pendiente_con_items(): void
    {
        $operario = User::factory()->create(['rol' => Rol::Operario]);
        $cliente = Cliente::factory()->create();

        $response = $this->actingAs($operario)->postJson('/api/v1/solicitudes', [
            'cliente_id' => $cliente->id,
            'descripcion' => 'Necesito mantenimiento de tres equipos.',
            'items' => [
                ['descripcion' => 'Revisión de CPU', 'cantidad' => 3, 'unidad_medida' => 'equipo'],
                ['descripcion' => 'Limpieza interna', 'cantidad' => 3, 'unidad_medida' => 'equipo'],
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('estado', 'PENDIENTE')
            ->assertJsonPath('cliente_id', $cliente->id)
            ->assertJsonPath('created_by', $operario->id)
            ->assertJsonPath('convertida_proforma_id', null)
            ->assertJsonCount(2, 'items')
            ->assertJsonPath('items.0.descripcion', 'Revisión de CPU');

        $this->assertDatabaseHas('solicitudes_cotizacion', [
            'cliente_id' => $cliente->id,
            'estado' => 'PENDIENTE',
        ]);
    }

    public function test_conversion_crea_proforma_borrador_y_marca_solicitud_atendida(): void
    {
        $operario = User::factory()->create(['rol' => Rol::Operario]);
        $solicitud = SolicitudCotizacion::factory()->create();
        SolicitudItem::factory()->for($solicitud, 'solicitud')->create([
            'descripcion' => 'Cambio de pantalla',
            'cantidad' => 2,
            'unidad_medida' => 'unidad',
        ]);

        $response = $this->actingAs($operario)->postJson("/api/v1/solicitudes/{$solicitud->id}/convertir");

        $response->assertCreated()
            ->assertJsonPath('estado', 'BORRADOR')
            ->assertJsonPath('origen', 'SOLICITUD')
            ->assertJsonPath('solicitud_id', $solicitud->id)
            ->assertJsonPath('items.0.tipo', 'LIBRE')
            ->assertJsonPath('items.0.descripcion', 'Cambio de pantalla')
            ->assertJsonPath('items.0.cantidad', 2)
            ->assertJsonPath('items.0.precio_unitario', 0);

        $proformaId = $response->json('id');

        $this->assertDatabaseHas('solicitudes_cotizacion', [
            'id' => $solicitud->id,
            'estado' => 'ATENDIDA',
            'convertida_proforma_id' => $proformaId,
        ]);

        $this->assertDatabaseHas('proformas', [
            'id' => $proformaId,
            'origen' => 'SOLICITUD',
            'solicitud_id' => $solicitud->id,
        ]);
    }

    public function test_no_se_convierte_dos_veces_la_misma_solicitud(): void
    {
        $operario = User::factory()->create(['rol' => Rol::Operario]);
        $solicitud = SolicitudCotizacion::factory()->create();
        SolicitudItem::factory()->for($solicitud, 'solicitud')->create();

        $this->actingAs($operario)->postJson("/api/v1/solicitudes/{$solicitud->id}/convertir")
            ->assertCreated();

        $this->actingAs($operario)->postJson("/api/v1/solicitudes/{$solicitud->id}/convertir")
            ->assertStatus(422)
            ->assertJsonValidationErrors('estado');
    }

    public function test_conversion_de_solicitud_sin_items_genera_una_linea_libre(): void
    {
        $operario = User::factory()->create(['rol' => Rol::Operario]);
        $solicitud = SolicitudCotizacion::factory()->create(['descripcion' => 'Consulta general']);

        $this->actingAs($operario)->postJson("/api/v1/solicitudes/{$solicitud->id}/convertir")
            ->assertCreated()
            ->assertJsonPath('items.0.tipo', 'LIBRE')
            ->assertJsonPath('items.0.descripcion', 'Consulta general');
    }

    public function test_cliente_no_accede_a_solicitudes(): void
    {
        $cliente = User::factory()->create(['rol' => Rol::Cliente]);

        $this->actingAs($cliente)->getJson('/api/v1/solicitudes')->assertForbidden();
        $this->actingAs($cliente)->postJson('/api/v1/solicitudes', [])->assertForbidden();
    }

    public function test_solo_admin_elimina_solicitud_pendiente(): void
    {
        $operario = User::factory()->create(['rol' => Rol::Operario]);
        $admin = User::factory()->create(['rol' => Rol::Admin]);
        $solicitud = SolicitudCotizacion::factory()->create();

        $this->actingAs($operario)->deleteJson("/api/v1/solicitudes/{$solicitud->id}")
            ->assertForbidden();

        $this->actingAs($admin)->deleteJson("/api/v1/solicitudes/{$solicitud->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('solicitudes_cotizacion', ['id' => $solicitud->id]);
    }

    public function test_solicitud_atendida_no_se_edita(): void
    {
        $operario = User::factory()->create(['rol' => Rol::Operario]);
        $solicitud = SolicitudCotizacion::factory()->atendida()->create();

        $this->actingAs($operario)->putJson("/api/v1/solicitudes/{$solicitud->id}", [
            'descripcion' => 'Cambio',
        ])->assertStatus(422)->assertJsonValidationErrors('estado');
    }
}
