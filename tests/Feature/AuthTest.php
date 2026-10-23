<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_ok_devuelve_usuario(): void
    {
        User::factory()->create(['email' => 'a@x.com', 'password' => 'secret123']);

        $response = $this->postJson('/api/login', [
            'email' => 'a@x.com',
            'password' => 'secret123',
        ]);

        $response->assertOk()->assertJsonPath('email', 'a@x.com');
        $this->assertAuthenticated();
    }

    public function test_login_con_password_invalido_devuelve_401_json(): void
    {
        User::factory()->create(['email' => 'a@x.com', 'password' => 'secret123']);

        $this->postJson('/api/login', ['email' => 'a@x.com', 'password' => 'otra'])
            ->assertStatus(401)
            ->assertJsonPath('message', 'Credenciales inválidas.');
    }

    public function test_invitado_no_accede_a_clientes(): void
    {
        $this->getJson('/api/clientes')->assertStatus(401);
        $this->getJson('/api/productos')->assertStatus(401);
        $this->getJson('/api/proformas')->assertStatus(401);
    }

    public function test_autenticado_consulta_su_perfil(): void
    {
        $user = User::factory()->create(['rol' => 'vendedor']);

        $this->actingAs($user)
            ->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('id', $user->id)
            ->assertJsonPath('rol', 'vendedor');
    }

    public function test_logout_cierra_sesion(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/logout')->assertNoContent();
        $this->assertGuest('web');
    }

    public function test_invitado_sin_accept_json_recibe_401_json(): void
    {
        $response = $this->call('GET', '/api/user', [], [], [], [
            'HTTP_ACCEPT' => 'text/html',
        ]);

        $response->assertStatus(401)
            ->assertJsonPath('message', 'No autenticado.');
    }
}
