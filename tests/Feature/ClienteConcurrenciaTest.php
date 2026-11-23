<?php

namespace Tests\Feature;

use App\Enums\EstadoCliente;
use App\Enums\Rol;
use App\Models\Cliente;
use App\Models\Empresa;
use App\Models\Proforma;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * C-05: dos personas editan el mismo cliente. Gana la primera; la segunda
 * recibe 409 con quién y cuándo lo cambió, y sus datos no pisan los otros.
 */
class ClienteConcurrenciaTest extends TestCase
{
    use RefreshDatabase;

    public function test_el_cliente_expone_su_version_y_cada_guardado_la_sube(): void
    {
        $admin = User::factory()->create(['rol' => Rol::Admin]);

        $creado = $this->actingAs($admin)->postJson('/api/v1/clientes', ['nombre_razon' => 'Constructora Andina'])
            ->assertCreated()
            ->assertJsonPath('version', 1)
            ->json();

        $this->actingAs($admin)->putJson("/api/v1/clientes/{$creado['id']}", ['telefono' => '70011223', 'version' => 1])
            ->assertOk()
            ->assertJsonPath('version', 2)
            ->assertJsonPath('telefono', '70011223');

        $this->assertSame($admin->id, Cliente::findOrFail($creado['id'])->updated_by);
    }

    public function test_el_segundo_guardado_con_la_misma_version_recibe_409_y_no_pisa_al_primero(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-17 10:32:00', 'America/La_Paz'));

        $ana = User::factory()->create(['rol' => Rol::Admin, 'name' => 'Ana Torres']);
        $beto = User::factory()->create(['rol' => Rol::Operario, 'name' => 'Beto Rojas']);
        $cliente = Cliente::factory()->create(['nombre_razon' => 'Hotel Illimani', 'telefono' => '111']);

        // Los dos abrieron el formulario con la versión 1.
        $this->actingAs($ana)->putJson("/api/v1/clientes/{$cliente->id}", ['telefono' => '222', 'version' => 1])
            ->assertOk();

        $respuesta = $this->actingAs($beto)->putJson("/api/v1/clientes/{$cliente->id}", ['telefono' => '333', 'direccion' => 'Calle Beto', 'version' => 1])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Otro usuario modificó este cliente (Ana Torres, 17/11/2026 10:32) mientras lo editabas. Recarga para ver sus cambios; los tuyos no se guardaron.')
            // El cliente actual viaja en la respuesta para recargar el formulario.
            ->assertJsonPath('cliente.telefono', '222')
            ->assertJsonPath('cliente.version', 2);

        $this->assertArrayNotHasKey('errors', $respuesta->json());

        $cliente->refresh();
        $this->assertSame('222', $cliente->telefono);
        $this->assertNotSame('Calle Beto', $cliente->direccion);
        $this->assertSame(2, $cliente->version);
        $this->assertSame($ana->id, $cliente->updated_by);

        // Con la versión nueva, Beto ya puede guardar.
        $this->actingAs($beto)->putJson("/api/v1/clientes/{$cliente->id}", ['telefono' => '333', 'version' => 2])
            ->assertOk()
            ->assertJsonPath('version', 3);
    }

    public function test_una_pestana_con_el_front_anterior_sin_version_recibe_428_con_un_mensaje_claro(): void
    {
        $admin = User::factory()->create(['rol' => Rol::Admin]);
        $cliente = Cliente::factory()->create(['telefono' => '111']);

        $respuesta = $this->actingAs($admin)->putJson("/api/v1/clientes/{$cliente->id}", ['telefono' => '999'])
            ->assertStatus(428)
            ->assertJsonPath('message', 'Esta pantalla está desactualizada y no se guardó ningún cambio. Recarga la página (F5) y vuelve a editar el cliente.');

        // Sin `errors`: el front no marca ningún campo, muestra el mensaje.
        $this->assertArrayNotHasKey('errors', $respuesta->json());
        $this->assertSame('111', $cliente->fresh()->telefono);
        $this->assertSame(1, $cliente->fresh()->version);

        // Una versión inválida es el mismo caso.
        $this->actingAs($admin)->putJson("/api/v1/clientes/{$cliente->id}", ['telefono' => '999', 'version' => 'x'])
            ->assertStatus(428);

        // Los demás errores siguen siendo 422 por campo.
        $this->actingAs($admin)->putJson("/api/v1/clientes/{$cliente->id}", ['email' => 'no-es-correo', 'version' => 1])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    public function test_sin_usuario_conocido_el_mensaje_dice_cuando_sin_parentesis_vacios(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-17 10:32:00', 'America/La_Paz'));

        $admin = User::factory()->create(['rol' => Rol::Admin]);
        $cliente = Cliente::factory()->create();

        $this->actingAs($admin)->putJson("/api/v1/clientes/{$cliente->id}", ['telefono' => '222', 'version' => 1])->assertOk();
        // Quien guardó ya no existe (la FK pasa a null al eliminar el usuario).
        Cliente::query()->whereKey($cliente->id)->update(['updated_by' => null]);

        $this->actingAs($admin)->putJson("/api/v1/clientes/{$cliente->id}", ['telefono' => '333', 'version' => 1])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Otra persona modificó este cliente el 17/11/2026 a las 10:32 mientras lo editabas. Recarga para ver sus cambios; los tuyos no se guardaron.');
    }

    public function test_activar_y_desactivar_tambien_suben_la_version(): void
    {
        $operario = User::factory()->create(['rol' => Rol::Operario]);
        $cliente = Cliente::factory()->create(['estado' => EstadoCliente::Activo]);

        $this->actingAs($operario)->putJson("/api/v1/clientes/{$cliente->id}", ['estado' => 'inactivo', 'version' => 1])
            ->assertOk()
            ->assertJsonPath('version', 2);

        $this->actingAs($operario)->putJson("/api/v1/clientes/{$cliente->id}", ['estado' => 'activo', 'version' => 2])
            ->assertOk()
            ->assertJsonPath('version', 3);

        // Un formulario abierto antes de desactivar no puede reactivarlo a ciegas.
        $this->actingAs($operario)->putJson("/api/v1/clientes/{$cliente->id}", ['estado' => 'inactivo', 'version' => 2])
            ->assertStatus(409);
        $this->assertSame(EstadoCliente::Activo, $cliente->fresh()->estado);
    }

    public function test_crear_y_registrarse_empiezan_en_la_version_1(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Cliente Portal',
            'email' => 'portal@cliente.test',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ])->assertSuccessful();

        $this->assertSame(1, Cliente::query()->where('email', 'portal@cliente.test')->value('version'));
    }

    public function test_si_otro_lo_elimino_el_guardado_da_404_y_no_lo_revive(): void
    {
        $admin = User::factory()->create(['rol' => Rol::Admin]);
        $cliente = Cliente::factory()->create();

        $this->actingAs($admin)->deleteJson("/api/v1/clientes/{$cliente->id}")->assertNoContent();

        $this->actingAs($admin)->putJson("/api/v1/clientes/{$cliente->id}", ['telefono' => '1', 'version' => 1])
            ->assertNotFound();
        $this->assertNull(Cliente::query()->find($cliente->id));
    }

    public function test_el_cliente_no_edita_sus_datos_ni_desde_el_portal_ni_desde_la_api_interna(): void
    {
        $cliente = Cliente::factory()->create(['telefono' => '111']);
        $usuario = User::factory()->create(['rol' => Rol::Cliente, 'cliente_id' => $cliente->id]);

        $this->actingAs($usuario)->putJson("/api/v1/clientes/{$cliente->id}", ['telefono' => '999', 'version' => 1])
            ->assertForbidden();

        // El portal no tiene ninguna ruta que edite los datos del cliente.
        // Aceptar una proforma solo cambia Prospecto → Activo (y sube la
        // versión: PortalRespuestaProformaTest). Ronda B: `mis-datos` solo
        // sirve una vez, a la cuenta creada con Google que debe completar
        // teléfono y NIT/CI (GoogleLoginTest); a este cliente le da 403.
        $escrituras = collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn ($ruta) => str_starts_with($ruta->uri(), 'api/v1/portal'))
            ->filter(fn ($ruta) => array_intersect($ruta->methods(), ['POST', 'PUT', 'PATCH', 'DELETE']) !== [])
            ->map(fn ($ruta) => $ruta->uri())
            ->values()
            ->all();

        $this->assertSame([
            'api/v1/portal/mis-datos',
            'api/v1/portal/solicitudes',
            'api/v1/portal/proformas/{id}/aceptar',
            'api/v1/portal/proformas/{id}/rechazar',
        ], $escrituras);
        $this->actingAs($usuario)->putJson('/api/v1/portal/mis-datos', ['telefono' => '71234567', 'nit_ci' => '1234567'])
            ->assertForbidden();
        $this->assertSame('111', $cliente->fresh()->telefono);
        $this->assertSame(1, $cliente->fresh()->version);
    }

    public function test_aceptar_una_proforma_que_activa_al_prospecto_tambien_sube_la_version(): void
    {
        $operario = User::factory()->create(['rol' => Rol::Operario]);
        $cliente = Cliente::factory()->create(['estado' => EstadoCliente::Prospecto]);
        $proforma = Proforma::factory()->enviada()->create(['cliente_id' => $cliente->id, 'total' => 100, 'subtotal' => 100]);

        $this->actingAs($operario)->postJson("/api/v1/proformas/{$proforma->id}/aceptar")->assertOk();

        $cliente->refresh();
        $this->assertSame(EstadoCliente::Activo, $cliente->estado);
        $this->assertSame(2, $cliente->version);

        // Un formulario abierto antes (versión 1) ya no puede volver a "prospecto".
        $this->actingAs($operario)->putJson("/api/v1/clientes/{$cliente->id}", ['estado' => 'prospecto', 'version' => 1])
            ->assertStatus(409);
        $this->assertSame(EstadoCliente::Activo, $cliente->fresh()->estado);
    }

    public function test_un_cliente_de_otra_empresa_sigue_dando_404(): void
    {
        $admin = User::factory()->create(['rol' => Rol::Admin]);
        $ajeno = Cliente::factory()->create(['empresa_id' => Empresa::factory()]);

        $this->actingAs($admin)->putJson("/api/v1/clientes/{$ajeno->id}", ['telefono' => '1', 'version' => 1])
            ->assertNotFound();
    }
}
