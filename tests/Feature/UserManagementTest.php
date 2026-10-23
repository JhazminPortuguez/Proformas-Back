<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_lista_usuarios(): void
    {
        $admin = User::factory()->create(['rol' => 'admin']);

        $this->actingAs($admin)->getJson('/api/users')->assertOk();
    }

    public function test_vendedor_no_accede_a_usuarios_403(): void
    {
        $vendedor = User::factory()->create(['rol' => 'vendedor']);

        $this->actingAs($vendedor)->getJson('/api/users')->assertForbidden();
    }

    public function test_admin_crea_usuario_201_sin_exponer_password(): void
    {
        $admin = User::factory()->create(['rol' => 'admin']);

        $response = $this->actingAs($admin)->postJson('/api/users', [
            'name' => 'Yeni Vendedora',
            'email' => 'yeni@x.com',
            'password' => 'secret123',
            'rol' => 'vendedor',
        ]);

        $response->assertCreated()
            ->assertJsonPath('email', 'yeni@x.com')
            ->assertJsonMissing(['password' => 'secret123']);
        $this->assertSame('vendedor', User::where('email', 'yeni@x.com')->first()->rol);
    }

    public function test_rol_desconocido_se_rechaza_422(): void
    {
        $admin = User::factory()->create(['rol' => 'admin']);

        $this->actingAs($admin)->postJson('/api/users', [
            'name' => 'X',
            'email' => 'x@x.com',
            'password' => 'secret123',
            'rol' => 'jefe',
        ])->assertStatus(422);
    }
}
