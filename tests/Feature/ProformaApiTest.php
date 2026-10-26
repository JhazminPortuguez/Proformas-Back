<?php

namespace Tests\Feature;

use App\Enums\EstadoProforma;
use App\Enums\Rol;
use App\Models\Cliente;
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
