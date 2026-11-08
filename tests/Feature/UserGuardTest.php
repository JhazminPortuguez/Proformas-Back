<?php

namespace Tests\Feature;

use App\Enums\EstadoCliente;
use App\Enums\Rol;
use App\Models\Cliente;
use App\Models\Empresa;
use App\Models\Proforma;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_no_puede_eliminarse_a_si_mismo(): void
    {
        $admin = User::factory()->create(['rol' => Rol::Admin]);

        $this->actingAs($admin)->deleteJson("/api/v1/users/{$admin->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('users', ['id' => $admin->id]);
    }

    public function test_no_se_puede_degradar_al_ultimo_admin(): void
    {
        $admin = User::factory()->create(['rol' => Rol::Admin]);

        $this->actingAs($admin)->putJson("/api/v1/users/{$admin->id}", ['rol' => 'operario'])
            ->assertStatus(422);

        $this->assertSame(Rol::Admin, $admin->fresh()->rol);
    }

    public function test_admin_puede_degradar_a_otro_admin_si_quedan_dos(): void
    {
        $admin = User::factory()->create(['rol' => Rol::Admin]);
        $otro = User::factory()->create(['rol' => Rol::Admin]);

        $this->actingAs($admin)->putJson("/api/v1/users/{$otro->id}", ['rol' => 'operario'])
            ->assertOk()
            ->assertJsonPath('rol', 'operario');
    }

    public function test_admin_no_puede_autodegradarse_aunque_haya_otro_admin(): void
    {
        $admin = User::factory()->create(['rol' => Rol::Admin]);
        User::factory()->create(['rol' => Rol::Admin]);

        $this->actingAs($admin)->putJson("/api/v1/users/{$admin->id}", ['rol' => 'operario'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'No puedes quitarte tu propio rol de administrador.');

        $this->assertSame(Rol::Admin, $admin->fresh()->rol);
    }

    public function test_admin_no_puede_desactivar_su_propia_cuenta(): void
    {
        $admin = User::factory()->create(['rol' => Rol::Admin]);

        $this->actingAs($admin)->putJson("/api/v1/users/{$admin->id}", ['activo' => false])
            ->assertStatus(422)
            ->assertJsonPath('message', 'No puedes desactivar tu propia cuenta.');

        $this->assertTrue($admin->fresh()->activo);
    }

    public function test_admin_asigna_rol_y_limpia_cliente_id(): void
    {
        $admin = User::factory()->create(['rol' => Rol::Admin]);
        $cliente = Cliente::factory()->create();
        $portal = User::factory()->create([
            'rol' => Rol::Cliente,
            'cliente_id' => $cliente->id,
        ]);

        $this->actingAs($admin)->putJson("/api/v1/users/{$portal->id}", [
            'rol' => 'operario',
            'activo' => false,
        ])
            ->assertOk()
            ->assertJsonPath('rol', 'operario')
            ->assertJsonPath('activo', false)
            ->assertJsonPath('cliente_id', null);

        $this->assertDatabaseHas('users', [
            'id' => $portal->id,
            'cliente_id' => null,
            'activo' => false,
        ]);
    }

    public function test_admin_crea_usuario_interno_sin_cliente_id(): void
    {
        $admin = User::factory()->create(['rol' => Rol::Admin]);
        $cliente = Cliente::factory()->create(['estado' => EstadoCliente::Activo]);

        $this->actingAs($admin)->postJson('/api/v1/users', [
            'name' => 'Operaria',
            'email' => 'operaria@x.com',
            'password' => 'secret123',
            'rol' => 'operario',
            'cliente_id' => $cliente->id,
        ])
            ->assertCreated()
            ->assertJsonPath('cliente_id', null);
    }

    public function test_no_se_asigna_rol_cliente_sin_cliente_vinculado(): void
    {
        $admin = User::factory()->create(['rol' => Rol::Admin]);
        $usuario = User::factory()->create(['rol' => Rol::Operario]);

        $this->actingAs($admin)->putJson("/api/v1/users/{$usuario->id}", [
            'rol' => 'cliente',
        ])->assertStatus(422);
    }

    public function test_admin_asigna_rol_cliente_con_cliente_vinculado(): void
    {
        $admin = User::factory()->create(['rol' => Rol::Admin]);
        $cliente = Cliente::factory()->create();
        $usuario = User::factory()->create(['rol' => Rol::Operario]);

        $this->actingAs($admin)->putJson("/api/v1/users/{$usuario->id}", [
            'rol' => 'cliente',
            'cliente_id' => $cliente->id,
        ])
            ->assertOk()
            ->assertJsonPath('rol', 'cliente')
            ->assertJsonPath('cliente_id', $cliente->id);
    }

    public function test_admin_crea_usuario_cliente_vinculado_a_un_cliente_existente(): void
    {
        $admin = User::factory()->create(['rol' => Rol::Admin]);
        $cliente = Cliente::factory()->create(['email' => 'compras@acme.test']);
        Proforma::factory()->enviada()->create(['cliente_id' => $cliente->id]);

        $this->actingAs($admin)->postJson('/api/v1/users', [
            'name' => 'Compras ACME',
            'email' => 'compras@acme.test',
            'password' => 'secret123',
            'rol' => 'cliente',
            'cliente_id' => $cliente->id,
        ])
            ->assertCreated()
            ->assertJsonPath('rol', 'cliente')
            ->assertJsonPath('cliente_id', $cliente->id)
            ->assertJsonPath('cliente.nombre_razon', $cliente->nombre_razon);

        // Con ese usuario entra al portal y ve las proformas de su cliente.
        $usuario = User::where('email', 'compras@acme.test')->firstOrFail();
        $this->actingAs($usuario)->getJson('/api/v1/portal/proformas')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_crear_usuario_cliente_exige_un_cliente_de_la_empresa(): void
    {
        $admin = User::factory()->create(['rol' => Rol::Admin]);
        $datos = [
            'name' => 'Sin cliente',
            'email' => 'sin@cliente.test',
            'password' => 'secret123',
            'rol' => 'cliente',
        ];

        $this->actingAs($admin)->postJson('/api/v1/users', $datos)
            ->assertStatus(422)
            ->assertJsonPath('message', 'Un usuario con rol cliente debe estar vinculado a un cliente.');

        $otraEmpresa = Empresa::factory()->create();
        $ajeno = Cliente::factory()->create(['empresa_id' => $otraEmpresa->id]);

        $this->actingAs($admin)->postJson('/api/v1/users', [...$datos, 'cliente_id' => $ajeno->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('cliente_id');

        $this->assertDatabaseMissing('users', ['email' => 'sin@cliente.test']);
    }

    public function test_operario_no_crea_usuarios_cliente(): void
    {
        $operario = User::factory()->create(['rol' => Rol::Operario]);
        $cliente = Cliente::factory()->create();

        $this->actingAs($operario)->postJson('/api/v1/users', [
            'name' => 'X',
            'email' => 'x@cliente.test',
            'password' => 'secret123',
            'rol' => 'cliente',
            'cliente_id' => $cliente->id,
        ])->assertForbidden();
    }

    public function test_listado_de_usuarios_muestra_el_cliente_vinculado(): void
    {
        $admin = User::factory()->create(['rol' => Rol::Admin]);
        $cliente = Cliente::factory()->create(['nombre_razon' => 'Ferretería Sol']);
        User::factory()->create(['rol' => Rol::Cliente, 'cliente_id' => $cliente->id, 'email' => 'sol@cliente.test']);

        $usuarios = collect($this->actingAs($admin)->getJson('/api/v1/users')->assertOk()->json('data'));

        $this->assertSame('Ferretería Sol', $usuarios->firstWhere('email', 'sol@cliente.test')['cliente']['nombre_razon']);
        $this->assertNull($usuarios->firstWhere('id', $admin->id)['cliente']);
    }
}
