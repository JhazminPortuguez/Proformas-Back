<?php

namespace Tests\Feature;

use App\Enums\Rol;
use App\Models\Cliente;
use App\Models\Producto;
use App\Models\Proforma;
use App\Models\Servicio;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_operario_opera_datos_pero_no_elimina_ni_gestiona_usuarios(): void
    {
        $operario = User::factory()->create(['rol' => Rol::Operario]);
        $cliente = Cliente::factory()->create();
        $producto = Producto::factory()->create();
        $proforma = Proforma::factory()->create(['cliente_id' => $cliente->id]);
        $servicio = Servicio::factory()->create();

        $this->actingAs($operario)->getJson('/api/v1/clientes')->assertOk();
        $this->actingAs($operario)->getJson('/api/v1/productos')->assertOk();
        $this->actingAs($operario)->getJson('/api/v1/proformas')->assertOk();
        $this->actingAs($operario)->getJson('/api/v1/servicios')->assertOk();
        $this->actingAs($operario)->getJson("/api/v1/servicios/{$servicio->id}")->assertOk();

        $this->actingAs($operario)->postJson('/api/v1/clientes', ['nombre_razon' => 'Nuevo'])
            ->assertCreated();
        $this->actingAs($operario)->putJson("/api/v1/clientes/{$cliente->id}", ['nombre_razon' => 'Editado'])
            ->assertOk();

        $this->actingAs($operario)->postJson('/api/v1/productos', [
            'nombre' => 'X',
            'precio_unitario' => 10,
        ])->assertCreated();

        $this->actingAs($operario)->deleteJson("/api/v1/clientes/{$cliente->id}")
            ->assertForbidden();
        $this->actingAs($operario)->deleteJson("/api/v1/proformas/{$proforma->id}")
            ->assertForbidden();
        $this->actingAs($operario)->deleteJson("/api/v1/productos/{$producto->id}")
            ->assertForbidden();

        $this->actingAs($operario)->getJson('/api/v1/users')->assertForbidden();

        $this->actingAs($operario)->postJson('/api/v1/servicios', [
            'nombre' => 'Nuevo servicio',
            'precio_base' => 100,
        ])->assertForbidden();
        $this->actingAs($operario)->putJson("/api/v1/servicios/{$servicio->id}", [
            'precio_base' => 200,
        ])->assertForbidden();
        $this->actingAs($operario)->deleteJson("/api/v1/servicios/{$servicio->id}")
            ->assertForbidden();
    }

    public function test_cliente_no_accede_a_rutas_internas(): void
    {
        $cliente = User::factory()->create(['rol' => Rol::Cliente]);

        foreach (['clientes', 'productos', 'proformas', 'servicios', 'users', 'dashboard'] as $ruta) {
            $this->actingAs($cliente)->getJson("/api/v1/{$ruta}")->assertForbidden();
        }

        $this->actingAs($cliente)->postJson('/api/v1/clientes', ['nombre_razon' => 'X'])
            ->assertForbidden();
    }

    public function test_admin_elimina_proforma(): void
    {
        $admin = User::factory()->create(['rol' => Rol::Admin]);
        $proforma = Proforma::factory()->create();

        $this->actingAs($admin)->deleteJson("/api/v1/proformas/{$proforma->id}")
            ->assertNoContent();
    }

    public function test_operario_puede_ver_detalle(): void
    {
        $operario = User::factory()->create(['rol' => Rol::Operario]);
        $producto = Producto::factory()->create();

        $this->actingAs($operario)->getJson("/api/v1/productos/{$producto->id}")
            ->assertOk()
            ->assertJsonPath('id', $producto->id);
    }

    public function test_permiso_se_evalua_antes_de_la_validacion(): void
    {
        $operario = User::factory()->create(['rol' => Rol::Operario]);
        $portal = User::factory()->create(['rol' => Rol::Cliente]);

        $this->actingAs($operario)->postJson('/api/v1/servicios', [])
            ->assertForbidden()
            ->assertJsonPath('message', 'No tienes permisos para realizar esta acción.');

        $this->actingAs($portal)->postJson('/api/v1/clientes', [])
            ->assertForbidden();
    }
}
