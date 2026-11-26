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

    /**
     * RC-03 (ronda C): la contraseña inicial que escriben el administrador o
     * el superusuario cumple la misma regla que el registro, con los mismos
     * mensajes en español. Lo mismo al cambiarla desde la edición.
     */
    public function test_rc03_la_contrasena_inicial_exige_letras_y_numeros(): void
    {
        $admin = User::factory()->create(['rol' => Rol::Admin]);
        $superusuario = User::factory()->superusuario()->create();
        $casos = [
            ['solotexto', 'La contraseña debe tener al menos un número.'],
            ['12345678', 'La contraseña debe tener al menos una letra.'],
            ['abc123', 'La contraseña debe tener al menos 8 caracteres.'],
        ];

        foreach ([$admin, $superusuario] as $actor) {
            foreach ($casos as [$password, $mensaje]) {
                $this->actingAs($actor)->postJson('/api/v1/users', [
                    'name' => 'Débil',
                    'email' => 'debil'.$actor->id.'@x.com',
                    'password' => $password,
                    'rol' => 'operario',
                ])->assertUnprocessable()->assertJsonPath('errors.password.0', $mensaje);
            }
        }

        $this->assertDatabaseMissing('users', ['name' => 'Débil']);

        $this->actingAs($admin)->postJson('/api/v1/users', [
            'name' => 'Fuerte',
            'email' => 'fuerte@x.com',
            'password' => 'Clave2026x',
            'rol' => 'operario',
        ])->assertCreated();

        $operario = User::where('email', 'fuerte@x.com')->firstOrFail();

        $this->actingAs($admin)->putJson("/api/v1/users/{$operario->id}", ['password' => 'solotexto'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.password.0', 'La contraseña debe tener al menos un número.');
    }
}
