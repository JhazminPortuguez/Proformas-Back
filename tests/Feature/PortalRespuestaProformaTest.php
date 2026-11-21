<?php

namespace Tests\Feature;

use App\Enums\CanalRespuesta;
use App\Enums\EstadoCliente;
use App\Enums\EstadoOrden;
use App\Enums\EstadoProforma;
use App\Enums\Rol;
use App\Models\Cliente;
use App\Models\OrdenServicio;
use App\Models\Proforma;
use App\Models\ProformaItem;
use App\Models\User;
use App\Support\FechaBolivia;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P-11 / U-05: el cliente acepta o rechaza su proforma desde el portal.
 */
class PortalRespuestaProformaTest extends TestCase
{
    use RefreshDatabase;

    private Cliente $cliente;

    private User $usuario;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cliente = Cliente::factory()->create(['estado' => EstadoCliente::Activo]);
        $this->usuario = User::factory()->cliente()->create(['cliente_id' => $this->cliente->id, 'name' => 'Ana Cliente']);
    }

    private function enviada(?Cliente $cliente = null, array $extra = []): Proforma
    {
        $proforma = Proforma::factory()->enviada()->create([
            'cliente_id' => ($cliente ?? $this->cliente)->id,
            'subtotal' => 1250.75,
            'total' => 1250.75,
            ...$extra,
        ]);

        ProformaItem::factory()->servicio()->create(['proforma_id' => $proforma->id]);

        return $proforma;
    }

    public function test_el_cliente_acepta_su_proforma_y_queda_el_canal_y_quien(): void
    {
        $proforma = $this->enviada();

        $this->actingAs($this->usuario)->getJson("/api/v1/portal/proformas/{$proforma->id}")
            ->assertOk()
            ->assertJsonPath('puede_responder', true)
            ->assertJsonPath('motivo_no_puede_responder', null);

        $this->actingAs($this->usuario)->postJson("/api/v1/portal/proformas/{$proforma->id}/aceptar")
            ->assertOk()
            ->assertJsonPath('estado', 'ACEPTADA')
            ->assertJsonPath('puede_responder', false);

        $proforma->refresh();
        $this->assertSame(EstadoProforma::Aceptada, $proforma->estado);
        $this->assertSame(CanalRespuesta::Portal, $proforma->aceptada_canal);
        $this->assertSame($this->usuario->id, $proforma->aceptada_por);
        $this->assertNotNull($proforma->aceptada_at);

        // En el detalle interno se ve quién, cuándo y por qué canal.
        $admin = User::factory()->create(['rol' => Rol::Admin]);
        $this->actingAs($admin)->getJson("/api/v1/proformas/{$proforma->id}")
            ->assertOk()
            ->assertJsonPath('aceptada_canal', 'PORTAL')
            ->assertJsonPath('aceptador.name', 'Ana Cliente')
            ->assertJsonPath('aceptada_sin_orden', true);
    }

    public function test_un_prospecto_que_acepta_desde_el_portal_pasa_a_activo(): void
    {
        $this->cliente->update(['estado' => EstadoCliente::Prospecto]);
        $proforma = $this->enviada();

        $this->actingAs($this->usuario)->postJson("/api/v1/portal/proformas/{$proforma->id}/aceptar")->assertOk();

        $this->cliente->refresh();
        $this->assertSame(EstadoCliente::Activo, $this->cliente->estado);
        $this->assertSame(2, $this->cliente->version);
    }

    public function test_el_cliente_rechaza_con_motivo_opcional(): void
    {
        $sinMotivo = $this->enviada();
        $conMotivo = $this->enviada();

        $this->actingAs($this->usuario)->postJson("/api/v1/portal/proformas/{$sinMotivo->id}/rechazar")
            ->assertOk()
            ->assertJsonPath('estado', 'RECHAZADA');
        $this->actingAs($this->usuario)->postJson("/api/v1/portal/proformas/{$conMotivo->id}/rechazar", ['motivo' => '  Muy caro  '])
            ->assertOk();

        $sinMotivo->refresh();
        $this->assertNull($sinMotivo->motivo_rechazo);
        $this->assertSame(CanalRespuesta::Portal, $sinMotivo->rechazada_canal);
        $this->assertSame($this->usuario->id, $sinMotivo->rechazada_por);
        $this->assertSame('Muy caro', $conMotivo->fresh()->motivo_rechazo);

        $this->actingAs($this->usuario)->postJson("/api/v1/portal/proformas/{$this->enviada()->id}/rechazar", ['motivo' => str_repeat('a', 1001)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('motivo');
    }

    public function test_solo_responde_sus_proformas_enviadas(): void
    {
        $otro = Cliente::factory()->create();
        $ajena = $this->enviada($otro);
        $borrador = Proforma::factory()->create(['cliente_id' => $this->cliente->id, 'total' => 100]);

        foreach (['aceptar', 'rechazar'] as $accion) {
            $this->actingAs($this->usuario)->postJson("/api/v1/portal/proformas/{$ajena->id}/{$accion}")->assertNotFound();
            $this->actingAs($this->usuario)->postJson("/api/v1/portal/proformas/{$borrador->id}/{$accion}")->assertNotFound();
            $this->actingAs($this->usuario)->postJson("/api/v1/portal/proformas/999999/{$accion}")->assertNotFound();
        }

        $this->assertSame(EstadoProforma::Enviada, $ajena->fresh()->estado);
        $this->assertSame(EstadoProforma::Borrador, $borrador->fresh()->estado);
    }

    public function test_permisos_el_personal_no_usa_el_portal_y_el_cliente_no_usa_la_api_interna(): void
    {
        $proforma = $this->enviada();

        foreach ([Rol::Admin, Rol::Operario] as $rol) {
            $interno = User::factory()->create(['rol' => $rol]);
            $this->actingAs($interno)->postJson("/api/v1/portal/proformas/{$proforma->id}/aceptar")->assertForbidden();
        }

        $this->actingAs($this->usuario)->postJson("/api/v1/proformas/{$proforma->id}/aceptar")->assertForbidden();
        $this->actingAs($this->usuario)->postJson("/api/v1/proformas/{$proforma->id}/rechazar", ['motivo' => 'Caro'])->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->postJson("/api/v1/portal/proformas/{$proforma->id}/aceptar")->assertUnauthorized();

        $this->assertSame(EstadoProforma::Enviada, $proforma->fresh()->estado);
    }

    public function test_vencida_el_cliente_no_la_acepta_pero_el_personal_si(): void
    {
        $ayer = FechaBolivia::hoy()->subDay();
        $proforma = $this->enviada(extra: [
            'fecha_emision' => $ayer->subDays(10)->toDateString(),
            'fecha_validez' => $ayer->toDateString(),
        ]);
        $mensaje = 'Esta proforma venció el '.$ayer->format('d/m/Y').'. Comunícate con la empresa para actualizarla.';

        $this->actingAs($this->usuario)->getJson("/api/v1/portal/proformas/{$proforma->id}")
            ->assertJsonPath('vencida', true)
            ->assertJsonPath('puede_responder', false)
            ->assertJsonPath('motivo_no_puede_responder', $mensaje);

        $this->actingAs($this->usuario)->postJson("/api/v1/portal/proformas/{$proforma->id}/aceptar")
            ->assertUnprocessable()
            ->assertJsonPath('message', $mensaje);
        $this->assertSame(EstadoProforma::Enviada, $proforma->fresh()->estado);

        // El personal sí puede (con el aviso que ya muestra el front).
        $operario = User::factory()->create(['rol' => Rol::Operario]);
        $this->actingAs($operario)->postJson("/api/v1/proformas/{$proforma->id}/aceptar")->assertOk();
        $this->assertSame(CanalRespuesta::Interno, $proforma->fresh()->aceptada_canal);
    }

    public function test_un_cliente_inactivo_no_acepta_ni_rechaza(): void
    {
        $proforma = $this->enviada();
        $this->cliente->update(['estado' => EstadoCliente::Inactivo]);

        $this->actingAs($this->usuario)->getJson("/api/v1/portal/proformas/{$proforma->id}")
            ->assertJsonPath('puede_responder', false)
            ->assertJsonPath('motivo_no_puede_responder', 'Tu cuenta de cliente está inactiva, así que no puedes responder proformas desde el portal. Comunícate con la empresa.');

        $this->actingAs($this->usuario)->postJson("/api/v1/portal/proformas/{$proforma->id}/aceptar")->assertForbidden();
        $this->actingAs($this->usuario)->postJson("/api/v1/portal/proformas/{$proforma->id}/rechazar")->assertForbidden();

        $this->assertSame(EstadoProforma::Enviada, $proforma->fresh()->estado);
    }

    public function test_si_el_personal_respondio_antes_el_cliente_ve_un_mensaje_claro(): void
    {
        $operario = User::factory()->create(['rol' => Rol::Operario]);
        $aceptada = $this->enviada();
        $rechazada = $this->enviada();

        $this->actingAs($operario)->postJson("/api/v1/proformas/{$aceptada->id}/aceptar")->assertOk();
        $this->actingAs($operario)->postJson("/api/v1/proformas/{$rechazada->id}/rechazar", ['motivo' => 'Llamó para rechazar'])->assertOk();

        $this->actingAs($this->usuario)->postJson("/api/v1/portal/proformas/{$aceptada->id}/aceptar")
            ->assertStatus(409)
            ->assertJsonPath('message', fn (string $m) => str_starts_with($m, 'La empresa ya registró la aceptación de esta proforma el '));
        $this->actingAs($this->usuario)->postJson("/api/v1/portal/proformas/{$aceptada->id}/rechazar")
            ->assertStatus(409);
        $this->actingAs($this->usuario)->postJson("/api/v1/portal/proformas/{$rechazada->id}/aceptar")
            ->assertStatus(409)
            ->assertJsonPath('message', fn (string $m) => str_starts_with($m, 'La empresa ya registró el rechazo de esta proforma el '));

        $this->assertSame(EstadoProforma::Aceptada, $aceptada->fresh()->estado);
        $this->assertSame(CanalRespuesta::Interno, $aceptada->fresh()->aceptada_canal);
        $this->assertSame(EstadoProforma::Rechazada, $rechazada->fresh()->estado);
    }

    public function test_doble_aceptacion_del_mismo_cliente(): void
    {
        $proforma = $this->enviada();

        $this->actingAs($this->usuario)->postJson("/api/v1/portal/proformas/{$proforma->id}/aceptar")->assertOk();
        $primera = $proforma->fresh()->aceptada_at;

        $this->actingAs($this->usuario)->postJson("/api/v1/portal/proformas/{$proforma->id}/aceptar")
            ->assertStatus(409)
            ->assertJsonPath('message', fn (string $m) => str_starts_with($m, 'Ya aceptaste esta proforma el '));

        // El personal tampoco la acepta de nuevo, y la fecha no cambia.
        $operario = User::factory()->create(['rol' => Rol::Operario]);
        $this->actingAs($operario)->postJson("/api/v1/proformas/{$proforma->id}/aceptar")->assertUnprocessable();
        $this->assertEquals($primera, $proforma->fresh()->aceptada_at);
    }

    public function test_el_personal_sigue_registrando_la_aceptacion_por_telefono(): void
    {
        $proforma = $this->enviada();
        $operario = User::factory()->create(['rol' => Rol::Operario, 'name' => 'Oscar Operario']);

        $this->actingAs($operario)->postJson("/api/v1/proformas/{$proforma->id}/aceptar")
            ->assertOk()
            ->assertJsonPath('aceptada_canal', 'INTERNO')
            ->assertJsonPath('aceptador.name', 'Oscar Operario');

        $this->actingAs($operario)->postJson("/api/v1/proformas/{$this->enviada()->id}/rechazar", ['motivo' => 'Llamó'])
            ->assertOk()
            ->assertJsonPath('rechazada_canal', 'INTERNO')
            ->assertJsonPath('rechazador.name', 'Oscar Operario');
    }

    public function test_filtro_aceptadas_sin_orden(): void
    {
        $admin = User::factory()->create(['rol' => Rol::Admin]);
        $sinOrden = Proforma::factory()->aceptada()->create(['cliente_id' => $this->cliente->id, 'total' => 100]);
        $conOrden = Proforma::factory()->aceptada()->create(['cliente_id' => $this->cliente->id, 'total' => 100]);
        $conOrdenCancelada = Proforma::factory()->aceptada()->create(['cliente_id' => $this->cliente->id, 'total' => 100]);
        $this->enviada();

        OrdenServicio::factory()->create(['proforma_id' => $conOrden->id, 'cliente_id' => $this->cliente->id]);
        OrdenServicio::factory()->create([
            'proforma_id' => $conOrdenCancelada->id,
            'cliente_id' => $this->cliente->id,
            'estado' => EstadoOrden::Cancelada,
        ]);

        $ids = collect($this->actingAs($admin)->getJson('/api/v1/proformas?sin_orden=1')->assertOk()->json('data'))
            ->pluck('id')
            ->sort()
            ->values()
            ->all();

        $this->assertSame([$sinOrden->id, $conOrdenCancelada->id], $ids);

        $this->actingAs($admin)->getJson("/api/v1/proformas/{$conOrden->id}")
            ->assertJsonPath('aceptada_sin_orden', false);
    }
}
