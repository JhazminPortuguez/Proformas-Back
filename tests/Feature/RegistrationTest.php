<?php

namespace Tests\Feature;

use App\Enums\EstadoCliente;
use App\Enums\Rol;
use App\Models\Cliente;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registro_crea_cliente_prospecto_usuario_cliente_y_autologin(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'María Pérez',
            'email' => 'Maria@X.com',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
            'telefono' => '77712345',
        ]);

        $response->assertCreated()
            ->assertJsonPath('email', 'maria@x.com')
            ->assertJsonPath('rol', 'cliente')
            ->assertJsonPath('activo', true)
            ->assertJsonPath('empresa_id', 1)
            ->assertJsonPath('cliente.estado', 'prospecto')
            ->assertJsonPath('cliente.nombre_razon', 'María Pérez')
            ->assertJsonMissingPath('password');

        $this->assertAuthenticated();

        $usuario = User::where('email', 'maria@x.com')->firstOrFail();

        $this->assertSame(Rol::Cliente, $usuario->rol);
        $this->assertTrue($usuario->activo);
        $this->assertNotNull($usuario->cliente_id);
        $this->assertSame($usuario->cliente_id, $usuario->cliente->id);
        $this->assertSame('maria@x.com', $usuario->cliente->email);
        $this->assertSame('77712345', $usuario->cliente->telefono);
        $this->assertSame(EstadoCliente::Prospecto, $usuario->cliente->estado);
        $this->assertSame(1, Cliente::where('email', 'maria@x.com')->count());
    }

    public function test_registro_rechaza_el_correo_de_un_cliente_existente(): void
    {
        $existente = Cliente::factory()->create([
            'nombre_razon' => 'ACME SRL',
            'email' => 'contacto@acme.com',
            'telefono' => null,
            'estado' => EstadoCliente::Activo,
        ]);

        // Sin importar mayúsculas: nadie puede tomar el acceso de un cliente
        // solo por conocer su correo.
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Contacto ACME',
            'email' => 'Contacto@ACME.com',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
            'telefono' => '70000000',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'email' => 'Este correo ya pertenece a un cliente; pide a la empresa tu acceso.',
            ]);

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'contacto@acme.com']);
        $this->assertSame(1, Cliente::whereRaw('lower(email) = ?', ['contacto@acme.com'])->count());
        $this->assertDatabaseHas('clientes', [
            'id' => $existente->id,
            'nombre_razon' => 'ACME SRL',
            'estado' => 'activo',
            'telefono' => null,
        ]);
    }

    public function test_registro_rechaza_email_de_usuario_existente(): void
    {
        User::factory()->create(['email' => 'existente@x.com']);

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Duplicado',
            'email' => 'existente@x.com',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ])->assertStatus(422)->assertJsonValidationErrors('email');
    }

    /** RC-01 (ronda C, parte 3): la misma regla que "Cambiar contraseña". */
    public function test_registro_exige_8_caracteres_con_letras_y_numeros(): void
    {
        $casos = [
            ['corta1', 'La contraseña debe tener al menos 8 caracteres.'],
            ['12345678', 'La contraseña debe tener al menos una letra.'],
            ['solotexto', 'La contraseña debe tener al menos un número.'],
            ['', 'Escribe la contraseña.'],
        ];

        foreach ($casos as [$password, $mensaje]) {
            $this->postJson('/api/v1/auth/register', [
                'name' => 'Clave Débil',
                'email' => 'debil@x.com',
                'password' => $password,
                'password_confirmation' => $password,
            ])->assertStatus(422)->assertJsonPath('errors.password.0', $mensaje);
        }

        $this->assertSame(0, User::query()->where('email', 'debil@x.com')->count());

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Clave Buena',
            'email' => 'buena@x.com',
            'password' => 'Ñandú2026',
            'password_confirmation' => 'Ñandú2026',
        ])->assertCreated();
    }

    /** RC-02: las cuentas existentes con una contraseña anterior a la regla siguen entrando. */
    public function test_las_cuentas_existentes_no_se_tocan(): void
    {
        User::factory()->create(['email' => 'antigua@x.com', 'password' => 'password', 'rol' => Rol::Operario]);

        $this->postJson('/api/v1/auth/login', ['email' => 'antigua@x.com', 'password' => 'password'])->assertOk();
    }

    public function test_registro_valida_confirmacion_de_password(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Sin Confirmar',
            'email' => 'sinc@x.com',
            'password' => 'secret123',
            'password_confirmation' => 'otra',
        ])->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_registro_esta_limitado_a_cinco_por_minuto_por_ip(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->postJson('/api/v1/auth/register', [
                'name' => "Usuario {$i}",
                'email' => "usuario{$i}@x.com",
                'password' => 'secret123',
                'password_confirmation' => 'secret123',
            ])->assertCreated();
        }

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Usuario 6',
            'email' => 'usuario6@x.com',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ])->assertStatus(429);
    }
}
