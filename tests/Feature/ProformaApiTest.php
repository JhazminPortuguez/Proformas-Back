<?php

namespace Tests\Feature;

use App\Enums\EstadoProforma;
use App\Enums\Rol;
use App\Models\Cliente;
use App\Models\OrdenServicio;
use App\Models\Proforma;
use App\Models\ProformaItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProformaApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_listado_busca_por_cliente_y_expone_acciones(): void
    {
        $admin = User::factory()->create(['rol' => Rol::Admin]);
        $cliente = Cliente::factory()->create(['nombre_razon' => 'Taller Ñandú']);
        $proforma = Proforma::factory()->create([
            'cliente_id' => $cliente->id,
            'estado' => EstadoProforma::Borrador,
        ]);

        ProformaItem::factory()->create(['proforma_id' => $proforma->id]);

        $response = $this->actingAs($admin)->getJson('/api/v1/proformas?search=NANDU');

        $response->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.items_count', 1);

        $acciones = $response->json('data.0.acciones_disponibles');

        $this->assertContains('ver', $acciones);
        $this->assertContains('editar', $acciones);
        $this->assertContains('enviar', $acciones);
        $this->assertContains('eliminar', $acciones);
        $this->assertContains('pdf', $acciones);
    }

    public function test_listado_expone_el_numero_de_la_orden_de_las_aceptadas(): void
    {
        $admin = User::factory()->create(['rol' => Rol::Admin]);
        $conOrden = Proforma::factory()->aceptada()->create();
        $sinOrden = Proforma::factory()->aceptada()->create();
        $orden = OrdenServicio::factory()->paraProforma($conOrden)->create(['numero' => 'OS-2026-000042']);

        $data = collect($this->actingAs($admin)->getJson('/api/v1/proformas')->assertOk()->json('data'))
            ->keyBy('id');

        $this->assertSame($orden->id, $data[$conOrden->id]['orden_id']);
        $this->assertSame('OS-2026-000042', $data[$conOrden->id]['orden_numero']);
        $this->assertNull($data[$sinOrden->id]['orden_id']);
        $this->assertNull($data[$sinOrden->id]['orden_numero']);
        $this->assertNull($data[$sinOrden->id]['orden_operario_id']);
    }

    public function test_expone_el_operario_de_la_orden_para_saber_si_se_puede_abrir(): void
    {
        $admin = User::factory()->create(['rol' => Rol::Admin]);
        $operario = User::factory()->create(['rol' => Rol::Operario]);
        $asignada = Proforma::factory()->aceptada()->create();
        $sinAsignar = Proforma::factory()->aceptada()->create();
        OrdenServicio::factory()->paraProforma($asignada)->create(['operario_id' => $operario->id]);
        OrdenServicio::factory()->paraProforma($sinAsignar)->create(['operario_id' => null]);

        $data = collect($this->actingAs($admin)->getJson('/api/v1/proformas')->assertOk()->json('data'))
            ->keyBy('id');

        $this->assertSame($operario->id, $data[$asignada->id]['orden_operario_id']);
        $this->assertNull($data[$sinAsignar->id]['orden_operario_id']);

        $this->actingAs($operario)->getJson("/api/v1/proformas/{$asignada->id}")
            ->assertOk()
            ->assertJsonPath('orden_operario_id', $operario->id);
    }

    public function test_listado_busca_por_numero(): void
    {
        $admin = User::factory()->create(['rol' => Rol::Admin]);
        $proforma = Proforma::factory()->create(['estado' => EstadoProforma::Borrador]);

        $this->actingAs($admin)
            ->getJson('/api/v1/proformas?search='.urlencode($proforma->numero))
            ->assertOk()
            ->assertJsonPath('data.0.numero', $proforma->numero);
    }

    public function test_proforma_aceptada_expone_ver_crear_orden_y_pdf(): void
    {
        $admin = User::factory()->create(['rol' => Rol::Admin]);
        $proforma = Proforma::factory()->create(['estado' => EstadoProforma::Aceptada]);

        $acciones = $this->actingAs($admin)
            ->getJson("/api/v1/proformas/{$proforma->id}")
            ->assertOk()
            ->json('acciones_disponibles');

        $this->assertSame(['ver', 'crear_orden', 'pdf'], $acciones);
    }

    public function test_operario_puede_ver_editar_y_enviar_borrador_pero_no_eliminar(): void
    {
        $operario = User::factory()->create(['rol' => Rol::Operario]);
        $proforma = Proforma::factory()->create(['estado' => EstadoProforma::Borrador]);

        $acciones = $this->actingAs($operario)
            ->getJson("/api/v1/proformas/{$proforma->id}")
            ->assertOk()
            ->json('acciones_disponibles');

        $this->assertContains('editar', $acciones);
        $this->assertContains('enviar', $acciones);
        $this->assertNotContains('eliminar', $acciones);
    }
}
