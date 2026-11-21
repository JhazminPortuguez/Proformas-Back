<?php

namespace Tests\Feature;

use App\Enums\Rol;
use App\Http\Middleware\EnsureCorreoVerificado;
use App\Models\Cliente;
use App\Models\Proforma;
use App\Models\ProformaItem;
use App\Models\User;
use App\Notifications\VerificarCorreoCliente;
use App\Support\RespuestaPortal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Ronda A, parte 3: verificación del correo del cliente (MustVerifyEmail).
 */
class VerificacionCorreoTest extends TestCase
{
    use RefreshDatabase;

    private const FRONT = 'https://proformas.test';

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.frontend_url' => self::FRONT, 'mail.default' => 'array']);
    }

    private function clienteSinVerificar(): User
    {
        $cliente = Cliente::factory()->create(['email' => 'nuevo@correo.bo']);

        return User::factory()->cliente()->unverified()->create([
            'cliente_id' => $cliente->id,
            'email' => 'nuevo@correo.bo',
        ]);
    }

    /** El enlace del correo, como lo abre el navegador (por el front, que reenvía /api al back). */
    private function ruta(User $usuario): string
    {
        $enlace = VerificarCorreoCliente::enlace($usuario);
        $this->assertStringStartsWith(self::FRONT.'/api/v1/auth/verificar-correo/', $enlace);

        return substr($enlace, strlen(self::FRONT));
    }

    private function alFront(string $estado): string
    {
        return self::FRONT."/verificar-correo?estado={$estado}";
    }

    public function test_al_registrarse_recibe_el_enlace_y_queda_sin_verificar(): void
    {
        Notification::fake();

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Ana Nueva',
            'email' => 'ana@nueva.bo',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ])->assertCreated()->assertJsonPath('email_verified_at', null);

        $usuario = User::query()->where('email', 'ana@nueva.bo')->firstOrFail();
        $this->assertFalse($usuario->hasVerifiedEmail());

        Notification::assertSentTo($usuario, VerificarCorreoCliente::class, function ($notificacion) use ($usuario) {
            $correo = $notificacion->toMail($usuario);

            return str_starts_with($correo->subject, 'Verifica tu correo en el portal de ')
                && str_contains($correo->viewData['enlace'], '/api/v1/auth/verificar-correo/'.$usuario->id.'/')
                && $correo->viewData['minutos'] === 60;
        });
    }

    public function test_enlace_valido_verifica_y_vuelve_al_front(): void
    {
        $usuario = $this->clienteSinVerificar();

        $this->get($this->ruta($usuario))->assertRedirect($this->alFront('verificado'));
        $this->assertTrue($usuario->fresh()->hasVerifiedEmail());

        // Abrirlo otra vez no falla: avisa que ya estaba verificado.
        $this->get($this->ruta($usuario))->assertRedirect($this->alFront('ya-verificado'));
    }

    public function test_enlace_vencido_no_verifica(): void
    {
        $usuario = $this->clienteSinVerificar();
        $ruta = $this->ruta($usuario);

        $this->travel(61)->minutes();

        $this->get($ruta)->assertRedirect($this->alFront('vencido'));
        $this->assertFalse($usuario->fresh()->hasVerifiedEmail());
    }

    public function test_enlace_alterado_no_verifica(): void
    {
        $usuario = $this->clienteSinVerificar();
        $otro = User::factory()->cliente()->unverified()->create(['cliente_id' => Cliente::factory()->create()->id]);
        $ruta = $this->ruta($usuario);

        // Firma cambiada.
        $this->get(preg_replace('/signature=[0-9a-f]+/', 'signature='.str_repeat('0', 64), $ruta))
            ->assertRedirect($this->alFront('invalido'));
        // Otro usuario con la misma firma.
        $this->get(str_replace("/verificar-correo/{$usuario->id}/", "/verificar-correo/{$otro->id}/", $ruta))
            ->assertRedirect($this->alFront('invalido'));
        // Vencimiento alargado a mano.
        $this->get(preg_replace('/expires=(\d+)/', 'expires=9999999999', $ruta))
            ->assertRedirect($this->alFront('invalido'));
        // Sin firma.
        $this->get(strtok($ruta, '?'))->assertRedirect($this->alFront('invalido'));
        // Firma válida pero de un correo que ya cambió.
        $usuario->forceFill(['email' => 'cambiado@correo.bo'])->save();
        $this->get($ruta)->assertRedirect($this->alFront('invalido'));

        $this->assertFalse($usuario->fresh()->hasVerifiedEmail());
        $this->assertFalse($otro->fresh()->hasVerifiedEmail());
    }

    public function test_reenviar_enlace_con_limite_de_envios(): void
    {
        Notification::fake();
        $usuario = $this->clienteSinVerificar();

        for ($i = 0; $i < 3; $i++) {
            $this->actingAs($usuario)->postJson('/api/v1/auth/verificar-correo/reenviar')
                ->assertOk()
                ->assertJsonPath('enviado', true)
                ->assertJsonPath('message', 'Te enviamos un enlace nuevo a nuevo@correo.bo. Vence en 60 minutos.');
        }

        $this->actingAs($usuario)->postJson('/api/v1/auth/verificar-correo/reenviar')
            ->assertStatus(429)
            ->assertJsonPath('message', fn (string $m) => str_starts_with($m, 'Ya pediste varios enlaces seguidos.'));

        Notification::assertSentToTimes($usuario, VerificarCorreoCliente::class, 3);

        // Pasados 10 minutos puede pedir otro.
        $this->travel(11)->minutes();
        $this->actingAs($usuario)->postJson('/api/v1/auth/verificar-correo/reenviar')->assertOk();

        // Sin sesión, no.
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/auth/verificar-correo/reenviar')->assertUnauthorized();
    }

    public function test_ya_verificado_no_reenvia(): void
    {
        Notification::fake();
        $usuario = User::factory()->cliente()->create(['cliente_id' => Cliente::factory()->create()->id]);

        $this->actingAs($usuario)->postJson('/api/v1/auth/verificar-correo/reenviar')
            ->assertOk()
            ->assertJsonPath('enviado', false)
            ->assertJsonPath('message', 'Tu correo ya está verificado.');

        Notification::assertNothingSent();
    }

    public function test_sin_verificar_ve_su_portal_pero_no_envia_solicitudes_ni_responde(): void
    {
        $usuario = $this->clienteSinVerificar();
        $proforma = Proforma::factory()->enviada()->create(['cliente_id' => $usuario->cliente_id, 'total' => 100, 'subtotal' => 100]);
        ProformaItem::factory()->servicio()->create(['proforma_id' => $proforma->id]);

        $this->actingAs($usuario)->getJson('/api/v1/portal/resumen')
            ->assertOk()
            ->assertJsonPath('correo_verificado', false);
        $this->actingAs($usuario)->getJson('/api/v1/portal/proformas')->assertOk();
        $this->actingAs($usuario)->getJson("/api/v1/portal/proformas/{$proforma->id}")
            ->assertOk()
            ->assertJsonPath('puede_responder', false)
            ->assertJsonPath('motivo_no_puede_responder', RespuestaPortal::MENSAJE_SIN_VERIFICAR);

        $this->actingAs($usuario)->postJson('/api/v1/portal/solicitudes', [
            'descripcion' => 'Instalar 3 tomacorrientes',
            'items' => [['descripcion' => 'Tomacorriente', 'cantidad' => 3]],
        ])->assertForbidden()
            ->assertJsonPath('codigo', 'correo_no_verificado')
            ->assertJsonPath('message', EnsureCorreoVerificado::MENSAJE);
        $this->actingAs($usuario)->postJson("/api/v1/portal/proformas/{$proforma->id}/aceptar")->assertForbidden();
        $this->actingAs($usuario)->postJson("/api/v1/portal/proformas/{$proforma->id}/rechazar")->assertForbidden();

        $this->assertDatabaseCount('solicitudes_cotizacion', 0);
        $this->assertSame('ENVIADA', $proforma->fresh()->estado->value);

        // Al verificar, ya puede.
        $this->get($this->ruta($usuario))->assertRedirect($this->alFront('verificado'));
        $this->actingAs($usuario->fresh())->postJson("/api/v1/portal/proformas/{$proforma->id}/aceptar")->assertOk();
    }

    public function test_el_personal_no_necesita_verificar_y_el_admin_crea_usuarios_verificados(): void
    {
        $admin = User::factory()->unverified()->create(['rol' => Rol::Admin]);
        $operario = User::factory()->unverified()->create(['rol' => Rol::Operario]);
        $proforma = Proforma::factory()->enviada()->create(['total' => 100, 'subtotal' => 100]);

        $this->actingAs($operario)->postJson("/api/v1/proformas/{$proforma->id}/aceptar")->assertOk();
        $this->actingAs($admin)->getJson('/api/v1/clientes')->assertOk();

        $cliente = Cliente::factory()->create();
        $this->actingAs($admin)->postJson('/api/v1/users', [
            'name' => 'Cliente creado por el admin',
            'email' => 'creado@admin.bo',
            'password' => 'Password123!',
            'rol' => 'cliente',
            'cliente_id' => $cliente->id,
        ])->assertCreated()->assertJsonPath('email_verified_at', fn ($valor) => $valor !== null);
    }

    public function test_la_migracion_marca_solo_a_los_clientes_que_ya_existian(): void
    {
        $antes = now()->subDay();
        $clienteViejo = $this->clienteSinVerificar();
        $clienteViejo->forceFill(['created_at' => $antes])->save();
        $yaVerificado = User::factory()->cliente()->create([
            'cliente_id' => Cliente::factory()->create()->id,
            'email_verified_at' => $antes,
        ]);
        $operario = User::factory()->unverified()->create(['rol' => Rol::Operario]);

        $migracion = require database_path('migrations/2026_11_21_000003_mark_existing_clientes_as_verified.php');
        $migracion->up();

        $this->assertTrue($clienteViejo->fresh()->hasVerifiedEmail());
        $this->assertEquals($antes->toDateTimeString(), $yaVerificado->fresh()->email_verified_at->toDateTimeString());
        $this->assertNull($operario->fresh()->email_verified_at);
        $this->assertSame(0, DB::table('users')->where('rol', 'cliente')->whereNull('email_verified_at')->count());
    }
}
