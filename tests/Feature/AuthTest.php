<?php

namespace Tests\Feature;

use App\Enums\Rol;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_ok_devuelve_usuario(): void
    {
        User::factory()->create(['email' => 'a@x.com', 'password' => 'secret123']);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'a@x.com',
            'password' => 'secret123',
        ]);

        $response->assertOk()
            ->assertJsonPath('email', 'a@x.com')
            ->assertJsonPath('rol', 'cliente')
            ->assertJsonMissingPath('password');
        $this->assertAuthenticated();
    }

    public function test_login_con_password_invalido_devuelve_401_json(): void
    {
        User::factory()->create(['email' => 'a@x.com', 'password' => 'secret123']);

        $this->postJson('/api/v1/auth/login', ['email' => 'a@x.com', 'password' => 'otra'])
            ->assertStatus(401)
            ->assertJsonPath('message', 'Credenciales inválidas.');
    }

    public function test_login_de_usuario_inactivo_devuelve_403(): void
    {
        User::factory()->inactivo()->create([
            'email' => 'inactivo@x.com',
            'password' => 'secret123',
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'inactivo@x.com',
            'password' => 'secret123',
        ])
            ->assertForbidden()
            ->assertJsonPath('message', 'Tu cuenta está desactivada.');

        $this->assertGuest('web');
    }

    public function test_invitado_no_accede_a_rutas_internas(): void
    {
        $this->getJson('/api/v1/clientes')->assertStatus(401);
        $this->getJson('/api/v1/productos')->assertStatus(401);
        $this->getJson('/api/v1/proformas')->assertStatus(401);
        $this->getJson('/api/v1/servicios')->assertStatus(401);
        $this->getJson('/api/v1/dashboard')->assertStatus(401);
    }

    public function test_autenticado_consulta_su_perfil(): void
    {
        $user = User::factory()->create(['rol' => Rol::Operario]);

        $this->actingAs($user)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('id', $user->id)
            ->assertJsonPath('rol', 'operario')
            ->assertJsonPath('activo', true)
            ->assertJsonMissingPath('password');
    }

    public function test_la_sesion_persiste_entre_peticiones(): void
    {
        $user = User::factory()->create([
            'email' => 'sesion@x.com',
            'password' => 'secret123',
            'rol' => Rol::Operario,
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'sesion@x.com',
            'password' => 'secret123',
        ])->assertOk();

        // Simula una petición nueva: el guard debe recuperar al usuario desde
        // la sesión sin entrar en recursión por el global scope de empresa.
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('id', $user->id)
            ->assertJsonPath('empresa_id', 1);
    }

    public function test_logout_cierra_sesion(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/v1/auth/logout')->assertNoContent();
        $this->assertGuest('web');
    }

    public function test_invitado_sin_accept_json_recibe_401_json(): void
    {
        $response = $this->call('GET', '/api/v1/auth/me', [], [], [], [
            'HTTP_ACCEPT' => 'text/html',
        ]);

        $response->assertStatus(401)
            ->assertJsonPath('message', 'No autenticado.');
    }
}
