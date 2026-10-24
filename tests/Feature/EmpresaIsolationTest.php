<?php

namespace Tests\Feature;

use App\Enums\Rol;
use App\Models\Cliente;
use App\Models\Empresa;
use App\Models\Servicio;
use App\Models\User;
use App\Support\EmpresaActual;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmpresaIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_empresa_actual_usa_configuracion_para_invitados(): void
    {
        $this->assertSame(1, EmpresaActual::id());

        $empresa = Empresa::factory()->create();
        $usuario = User::factory()->create([
            'rol' => Rol::Admin,
            'empresa_id' => $empresa->id,
        ]);

        $this->actingAs($usuario);

        $this->assertSame($empresa->id, EmpresaActual::id());
    }

    public function test_usuario_de_empresa_2_no_ve_clientes_de_empresa_1(): void
    {
        $empresa2 = Empresa::factory()->create();
        $usuario2 = User::factory()->create([
            'rol' => Rol::Admin,
            'empresa_id' => $empresa2->id,
        ]);

        $cliente1 = Cliente::factory()->create();
        $cliente2 = Cliente::factory()->create(['empresa_id' => $empresa2->id]);

        $this->actingAs($usuario2)
            ->getJson('/api/v1/clientes')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $cliente2->id);

        $this->actingAs($usuario2)
            ->getJson("/api/v1/clientes/{$cliente1->id}")
            ->assertNotFound();
    }

    public function test_usuario_de_empresa_2_no_ve_servicios_de_empresa_1(): void
    {
        $empresa2 = Empresa::factory()->create();
        $usuario2 = User::factory()->create([
            'rol' => Rol::Admin,
            'empresa_id' => $empresa2->id,
        ]);

        $servicio1 = Servicio::factory()->create();
        $servicio2 = Servicio::factory()->create(['empresa_id' => $empresa2->id]);

        $this->actingAs($usuario2)
            ->getJson('/api/v1/servicios')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $servicio2->id);

        $this->actingAs($usuario2)
            ->getJson("/api/v1/servicios/{$servicio1->id}")
            ->assertNotFound();
    }

    public function test_creacion_autocompleta_empresa_del_usuario_autenticado(): void
    {
        $empresa2 = Empresa::factory()->create();
        $usuario2 = User::factory()->create([
            'rol' => Rol::Operario,
            'empresa_id' => $empresa2->id,
        ]);

        $this->actingAs($usuario2);

        $cliente = Cliente::create(['nombre_razon' => 'Cliente Empresa 2']);
        $servicio = Servicio::create([
            'nombre' => 'Servicio Empresa 2',
            'precio_base' => 100,
        ]);

        $this->assertSame($empresa2->id, $cliente->empresa_id);
        $this->assertSame($empresa2->id, $servicio->empresa_id);
    }
}
