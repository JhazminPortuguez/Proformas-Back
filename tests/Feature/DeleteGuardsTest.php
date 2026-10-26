<?php

namespace Tests\Feature;

use App\Enums\Rol;
use App\Models\Cliente;
use App\Models\Producto;
use App\Models\Proforma;
use App\Models\ProformaItem;
use App\Models\Servicio;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeleteGuardsTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_se_elimina_servicio_incluido_en_proformas(): void
    {
        $admin = User::factory()->create(['rol' => Rol::Admin]);
        $servicio = Servicio::factory()->create();
        $proforma = Proforma::factory()->create();

        ProformaItem::factory()->servicio()->create([
            'proforma_id' => $proforma->id,
            'servicio_id' => $servicio->id,
        ]);

        $this->actingAs($admin)->deleteJson("/api/v1/servicios/{$servicio->id}")
            ->assertStatus(409);

        $this->assertDatabaseHas('servicios', ['id' => $servicio->id]);
    }

    public function test_no_se_elimina_proforma_fuera_de_borrador(): void
    {
        $admin = User::factory()->create(['rol' => Rol::Admin]);
        $proforma = Proforma::factory()->aceptada()->create();

        $this->actingAs($admin)->deleteJson("/api/v1/proformas/{$proforma->id}")
            ->assertStatus(409);

        $this->assertDatabaseHas('proformas', ['id' => $proforma->id]);
    }

    public function test_no_se_elimina_usuario_con_historial_de_proformas(): void
    {
        $admin = User::factory()->create(['rol' => Rol::Admin]);
        $operario = User::factory()->create(['rol' => Rol::Operario]);
        Proforma::factory()->create(['created_by' => $operario->id]);

        $this->actingAs($admin)->deleteJson("/api/v1/users/{$operario->id}")
            ->assertStatus(409);

        $this->assertDatabaseHas('users', ['id' => $operario->id]);
    }

    public function test_admin_elimina_usuario_sin_historial(): void
    {
        $admin = User::factory()->create(['rol' => Rol::Admin]);
        $operario = User::factory()->create(['rol' => Rol::Operario]);

        $this->actingAs($admin)->deleteJson("/api/v1/users/{$operario->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('users', ['id' => $operario->id]);
    }

    public function test_no_se_elimina_cliente_con_proformas(): void
    {
        $admin = User::factory()->create(['rol' => Rol::Admin]);
        $cliente = Cliente::factory()->create();
        Proforma::factory()->create(['cliente_id' => $cliente->id]);

        $this->actingAs($admin)->deleteJson("/api/v1/clientes/{$cliente->id}")
            ->assertStatus(409);

        $this->assertDatabaseHas('clientes', ['id' => $cliente->id]);
    }

    public function test_no_se_elimina_producto_incluido_en_proformas(): void
    {
        $admin = User::factory()->create(['rol' => Rol::Admin]);
        $producto = Producto::factory()->create();
        $proforma = Proforma::factory()->create();

        ProformaItem::factory()->create([
            'proforma_id' => $proforma->id,
            'producto_id' => $producto->id,
        ]);

        $this->actingAs($admin)->deleteJson("/api/v1/productos/{$producto->id}")
            ->assertStatus(409);

        $this->assertDatabaseHas('productos', ['id' => $producto->id]);
    }

    public function test_admin_puede_eliminar_cliente_sin_proformas(): void
    {
        $admin = User::factory()->create(['rol' => Rol::Admin]);
        $cliente = Cliente::factory()->create();

        $this->actingAs($admin)->deleteJson("/api/v1/clientes/{$cliente->id}")
            ->assertNoContent();
    }
}
