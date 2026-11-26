<?php

namespace Tests\Feature;

use App\Enums\Rol;
use App\Http\Controllers\Api\OlvidePasswordController;
use App\Models\User;
use App\Notifications\CuentaSoloGoogle;
use App\Notifications\RestablecerContrasena;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Ronda C, parte 2: "Olvidé mi contraseña" (casos OC-01 a OC-07).
 */
class OlvidePasswordTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    /** OC-01: el mensaje es el mismo exista o no el correo. */
    public function test_el_mensaje_es_el_mismo_exista_o_no_el_correo(): void
    {
        $usuario = User::factory()->create(['email' => 'ana@example.com', 'rol' => Rol::Operario]);

        $existe = $this->postJson('/api/v1/auth/olvide-password', ['email' => 'ANA@example.com'])->assertOk();
        $noExiste = $this->postJson('/api/v1/auth/olvide-password', ['email' => 'nadie@example.com'])->assertOk();

        $this->assertSame(OlvidePasswordController::MENSAJE_SOLICITUD, $existe->json('message'));
        $this->assertSame($existe->json(), $noExiste->json());

        Notification::assertSentTo($usuario, RestablecerContrasena::class);
        Notification::assertSentTimes(RestablecerContrasena::class, 1);
    }

    public function test_un_usuario_inactivo_no_recibe_enlace_y_el_mensaje_no_cambia(): void
    {
        $inactivo = User::factory()->inactivo()->create(['email' => 'baja@example.com']);

        $this->postJson('/api/v1/auth/olvide-password', ['email' => 'baja@example.com'])
            ->assertOk()
            ->assertJsonPath('message', OlvidePasswordController::MENSAJE_SOLICITUD);

        Notification::assertNotSentTo($inactivo, RestablecerContrasena::class);
    }

    /** OC-02: la cuenta creada con Google recibe un aviso claro, no un enlace. */
    public function test_la_cuenta_que_solo_entra_con_google_recibe_el_aviso_de_usar_google(): void
    {
        $google = User::factory()->cliente()->create(['email' => 'g@example.com']);
        $google->forceFill(['google_id' => '123', 'solo_google' => true])->save();

        $this->postJson('/api/v1/auth/olvide-password', ['email' => 'g@example.com'])
            ->assertOk()
            ->assertJsonPath('message', OlvidePasswordController::MENSAJE_SOLICITUD);

        Notification::assertSentTo($google, CuentaSoloGoogle::class, function (CuentaSoloGoogle $aviso) use ($google): bool {
            $html = (string) $aviso->toMail($google)->render();

            return str_contains($html, 'Continuar con Google') && ! str_contains($html, 'restablecer-contrasena');
        });
        Notification::assertNotSentTo($google, RestablecerContrasena::class);
    }

    /** OC-03 y OC-04: el enlace sirve una sola vez y cierra las sesiones abiertas. */
    public function test_el_enlace_cambia_la_contrasena_una_sola_vez_y_cierra_las_sesiones(): void
    {
        config(['session.driver' => 'database']);
        $usuario = User::factory()->create(['email' => 'ana@example.com', 'rol' => Rol::Operario]);
        $token = $this->pedirEnlace($usuario);

        DB::table('sessions')->insert([
            'id' => 'sesion-abierta',
            'user_id' => $usuario->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'prueba',
            'payload' => '',
            'last_activity' => time(),
        ]);

        $this->postJson('/api/v1/auth/restablecer-password', [
            'token' => $token,
            'email' => 'ana@example.com',
            'password' => 'NuevaClave2026',
            'password_confirmation' => 'NuevaClave2026',
        ])->assertOk();

        $this->assertTrue(Hash::check('NuevaClave2026', $usuario->fresh()->password));
        $this->assertSame(0, DB::table('sessions')->where('user_id', $usuario->id)->count());

        // Segunda vez con el mismo enlace: ya no sirve.
        $this->postJson('/api/v1/auth/restablecer-password', [
            'token' => $token,
            'email' => 'ana@example.com',
            'password' => 'OtraClave2027',
            'password_confirmation' => 'OtraClave2027',
        ])->assertStatus(422)
            ->assertJsonPath('errors.token.0', OlvidePasswordController::MENSAJE_ENLACE_INVALIDO);

        $this->assertTrue(Hash::check('NuevaClave2026', $usuario->fresh()->password));

        // Y entra con la nueva.
        $this->postJson('/api/v1/auth/login', ['email' => 'ana@example.com', 'password' => 'NuevaClave2026'])->assertOk();
    }

    /** OC-05: el enlace vence en 60 minutos. */
    public function test_el_enlace_vence_a_los_60_minutos(): void
    {
        $usuario = User::factory()->create(['email' => 'ana@example.com']);
        $token = $this->pedirEnlace($usuario);

        $this->travel(61)->minutes();

        $this->postJson('/api/v1/auth/restablecer-password', [
            'token' => $token,
            'email' => 'ana@example.com',
            'password' => 'NuevaClave2026',
            'password_confirmation' => 'NuevaClave2026',
        ])->assertStatus(422)
            ->assertJsonPath('errors.token.0', OlvidePasswordController::MENSAJE_ENLACE_INVALIDO);
    }

    public function test_un_token_inventado_o_de_otro_correo_no_sirve(): void
    {
        $ana = User::factory()->create(['email' => 'ana@example.com']);
        User::factory()->create(['email' => 'beto@example.com']);
        $token = $this->pedirEnlace($ana);

        foreach ([['inventado', 'ana@example.com'], [$token, 'beto@example.com'], [$token, 'nadie@example.com']] as [$t, $email]) {
            $this->postJson('/api/v1/auth/restablecer-password', [
                'token' => $t,
                'email' => $email,
                'password' => 'NuevaClave2026',
                'password_confirmation' => 'NuevaClave2026',
            ])->assertStatus(422)
                ->assertJsonPath('errors.token.0', OlvidePasswordController::MENSAJE_ENLACE_INVALIDO);
        }
    }

    /** OC-06: la contraseña nueva exige 8 caracteres con letras y números. */
    public function test_la_contrasena_nueva_exige_letras_y_numeros(): void
    {
        $usuario = User::factory()->create(['email' => 'ana@example.com']);
        $token = $this->pedirEnlace($usuario);

        $casos = [
            ['corta1', 'La contraseña nueva debe tener al menos 8 caracteres.'],
            ['12345678', 'La contraseña nueva debe tener al menos una letra.'],
            ['solotexto', 'La contraseña nueva debe tener al menos un número.'],
        ];

        foreach ($casos as [$password, $mensaje]) {
            $this->postJson('/api/v1/auth/restablecer-password', [
                'token' => $token,
                'email' => 'ana@example.com',
                'password' => $password,
                'password_confirmation' => $password,
            ])->assertStatus(422)->assertJsonPath('errors.password.0', $mensaje);
        }

        $this->postJson('/api/v1/auth/restablecer-password', [
            'token' => $token,
            'email' => 'ana@example.com',
            'password' => 'Clave2026',
            'password_confirmation' => 'Clave2027',
        ])->assertStatus(422)->assertJsonPath('errors.password.0', 'La confirmación no coincide con la contraseña nueva.');
    }

    /** OC-07: pedidos seguidos se limitan (throttle). */
    public function test_los_pedidos_seguidos_se_limitan(): void
    {
        User::factory()->create(['email' => 'ana@example.com']);

        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/v1/auth/olvide-password', ['email' => 'ana@example.com'])->assertOk();
        }

        $this->postJson('/api/v1/auth/olvide-password', ['email' => 'ana@example.com'])
            ->assertStatus(429)
            ->assertJsonPath('message', fn (string $mensaje) => str_contains($mensaje, 'varios pedidos seguidos'));

        // Un correo que no existe se limita igual: no revela nada.
        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/v1/auth/olvide-password', ['email' => 'nadie@example.com'])->assertOk();
        }

        $this->postJson('/api/v1/auth/olvide-password', ['email' => 'nadie@example.com'])->assertStatus(429);
    }

    public function test_el_correo_lleva_al_front_y_dice_que_vence_en_60_minutos(): void
    {
        $usuario = User::factory()->create(['email' => 'ana@example.com']);
        $this->pedirEnlace($usuario);

        Notification::assertSentTo($usuario, RestablecerContrasena::class, function (RestablecerContrasena $aviso) use ($usuario): bool {
            $html = (string) $aviso->toMail($usuario)->render();

            return str_contains($html, rtrim((string) config('app.frontend_url'), '/').'/restablecer-contrasena?token=')
                && str_contains($html, '60 minutos')
                && str_contains($html, 'una sola vez');
        });
    }

    public function test_el_pedido_valida_el_correo(): void
    {
        $this->postJson('/api/v1/auth/olvide-password', ['email' => 'no-es-correo'])
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'Escribe un correo electrónico válido.');
    }

    private function pedirEnlace(User $usuario): string
    {
        $token = null;

        $this->postJson('/api/v1/auth/olvide-password', ['email' => $usuario->email])->assertOk();

        Notification::assertSentTo($usuario, RestablecerContrasena::class, function (RestablecerContrasena $aviso) use ($usuario, &$token): bool {
            parse_str((string) parse_url(RestablecerContrasena::enlace($usuario, (fn () => $this->token)->call($aviso)), PHP_URL_QUERY), $query);
            $token = $query['token'] ?? null;

            return true;
        });

        $this->assertNotNull($token);

        return (string) $token;
    }
}
