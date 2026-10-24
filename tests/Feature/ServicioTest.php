<?php

namespace Tests\Feature;

use App\Enums\Rol;
use App\Models\Servicio;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServicioTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_crea_actualiza_y_elimina_servicios(): void
    {
        $admin = User::factory()->create(['rol' => Rol::Admin]);

        $creado = $this->actingAs($admin)->postJson('/api/v1/servicios', [
            'nombre' => 'Instalación premium',
            'descripcion' => 'Instalación con soporte extendido.',
            'precio_base' => 1200.50,
            'duracion_estimada_min' => 180,
            'categoria' => 'Instalación',
            'activo' => true,
            'visible_catalogo' => true,
        ])
            ->assertCreated()
            ->assertJsonPath('nombre', 'Instalación premium')
            ->assertJsonPath('precio_base', 1200.5)
            ->assertJsonPath('duracion_estimada_min', 180)
            ->assertJsonPath('categoria', 'Instalación')
            ->assertJsonPath('activo', true)
            ->assertJsonPath('visible_catalogo', true)
            ->assertJsonPath('empresa_id', 1)
            ->json();

        $this->actingAs($admin)->putJson("/api/v1/servicios/{$creado['id']}", [
            'precio_base' => 1500,
            'activo' => false,
            'visible_catalogo' => false,
        ])
            ->assertOk()
            ->assertJsonPath('precio_base', 1500)
            ->assertJsonPath('activo', false)
            ->assertJsonPath('visible_catalogo', false);

        $this->actingAs($admin)->deleteJson("/api/v1/servicios/{$creado['id']}")
            ->assertNoContent();

        $this->assertDatabaseMissing('servicios', ['id' => $creado['id']]);
    }

    public function test_index_busca_y_filtra_servicios(): void
    {
        $admin = User::factory()->create(['rol' => Rol::Admin]);

        Servicio::factory()->create([
            'nombre' => 'Mantenimiento de equipos',
            'categoria' => 'Mantenimiento',
            'activo' => true,
            'visible_catalogo' => true,
        ]);
        Servicio::factory()->inactivo()->oculto()->create([
            'nombre' => 'Servicio oculto',
            'categoria' => 'Soporte',
        ]);

        $this->actingAs($admin)
            ->getJson('/api/v1/servicios?search=mantenimiento&activo=1&visible_catalogo=1')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.nombre', 'Mantenimiento de equipos');

        $this->actingAs($admin)
            ->getJson('/api/v1/servicios?activo=0')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.nombre', 'Servicio oculto');
    }

    public function test_operario_solo_lee_servicios(): void
    {
        $operario = User::factory()->create(['rol' => Rol::Operario]);
        $servicio = Servicio::factory()->create();

        $this->actingAs($operario)->getJson('/api/v1/servicios')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);

        $this->actingAs($operario)->getJson("/api/v1/servicios/{$servicio->id}")
            ->assertOk()
            ->assertJsonPath('id', $servicio->id);

        $this->actingAs($operario)->postJson('/api/v1/servicios', [
            'nombre' => 'Nuevo',
            'precio_base' => 10,
        ])->assertForbidden();

        $this->actingAs($operario)->putJson("/api/v1/servicios/{$servicio->id}", [
            'nombre' => 'Cambiado',
        ])->assertForbidden();

        $this->actingAs($operario)->deleteJson("/api/v1/servicios/{$servicio->id}")
            ->assertForbidden();
    }

    public function test_cliente_no_accede_a_servicios(): void
    {
        $cliente = User::factory()->create(['rol' => Rol::Cliente]);
        $servicio = Servicio::factory()->create();

        $this->actingAs($cliente)->getJson('/api/v1/servicios')->assertForbidden();
        $this->actingAs($cliente)->getJson("/api/v1/servicios/{$servicio->id}")->assertForbidden();
    }

    public function test_servicio_requiere_datos_validos(): void
    {
        $admin = User::factory()->create(['rol' => Rol::Admin]);

        $this->actingAs($admin)->postJson('/api/v1/servicios', [
            'nombre' => '',
            'precio_base' => 'gratis',
            'duracion_estimada_min' => 0,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['nombre', 'precio_base', 'duracion_estimada_min']);
    }
}
