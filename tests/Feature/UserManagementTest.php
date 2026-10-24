<?php

namespace Tests\Feature;

use App\Enums\Rol;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_lista_usuarios(): void
    {
        $admin = User::factory()->create(['rol' => Rol::Admin]);

        $this->actingAs($admin)->getJson('/api/v1/users')->assertOk();
    }

    public function test_operario_y_cliente_no_acceden_a_usuarios_403(): void
    {
        $operario = User::factory()->create(['rol' => Rol::Operario]);
        $cliente = User::factory()->create(['rol' => Rol::Cliente]);

        $this->actingAs($operario)->getJson('/api/v1/users')->assertForbidden();
        $this->actingAs($cliente)->getJson('/api/v1/users')->assertForbidden();
    }

    public function test_admin_crea_usuario_201_sin_exponer_password(): void
    {
        $admin = User::factory()->create(['rol' => Rol::Admin]);

        $response = $this->actingAs($admin)->postJson('/api/v1/users', [
            'name' => 'Yeni Operaria',
            'email' => 'Yeni@X.com',
            'password' => 'secret123',
            'rol' => 'operario',
        ]);

        $response->assertCreated()
            ->assertJsonPath('email', 'yeni@x.com')
            ->assertJsonPath('rol', 'operario')
            ->assertJsonPath('activo', true)
            ->assertJsonPath('cliente_id', null)
            ->assertJsonPath('empresa_id', 1)
            ->assertJsonMissingPath('password');

        $this->assertSame(Rol::Operario, User::where('email', 'yeni@x.com')->first()->rol);
    }

    public function test_admin_filtra_usuarios_por_rol(): void
    {
        $admin = User::factory()->create(['rol' => Rol::Admin]);
        User::factory()->create(['rol' => Rol::Operario]);

        $this->actingAs($admin)
            ->getJson('/api/v1/users?rol=operario')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.rol', 'operario');
    }

    public function test_rol_desconocido_se_rechaza_422(): void
    {
        $admin = User::factory()->create(['rol' => Rol::Admin]);

        $this->actingAs($admin)->postJson('/api/v1/users', [
            'name' => 'X',
            'email' => 'x@x.com',
            'password' => 'secret123',
            'rol' => 'vendedor',
        ])->assertStatus(422);
    }
}
