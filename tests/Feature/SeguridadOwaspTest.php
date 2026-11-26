<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Ronda B, parte 3: lo que se corrigió en la revisión OWASP Top 10 (2021).
 * El resto de los riesgos se cubre con las pruebas que cita
 * C:\VSC\SEGURIDAD_OWASP.md.
 */
class SeguridadOwaspTest extends TestCase
{
    use RefreshDatabase;

    // ---------------------------------------------------------------
    // A07: cambiar la propia contraseña
    // ---------------------------------------------------------------

    public function test_cambiar_la_contrasena_pide_la_actual_y_una_nueva_fuerte(): void
    {
        $usuario = User::factory()->superusuario()->create(['password' => 'Temporal123']);

        $this->actingAs($usuario)->putJson('/api/v1/auth/password', [
            'password_actual' => 'otra-cosa',
            'password' => 'NuevaClave2026',
            'password_confirmation' => 'NuevaClave2026',
        ])->assertStatus(422)->assertJsonPath('errors.password_actual.0', 'La contraseña actual no es correcta.');

        $this->actingAs($usuario)->putJson('/api/v1/auth/password', [
            'password_actual' => 'Temporal123',
            'password' => 'solotexto',
            'password_confirmation' => 'solotexto',
        ])->assertStatus(422)->assertJsonPath('errors.password.0', 'La contraseña nueva debe tener al menos un número.');

        $this->actingAs($usuario)->putJson('/api/v1/auth/password', [
            'password_actual' => 'Temporal123',
            'password' => 'Temporal123',
            'password_confirmation' => 'Temporal123',
        ])->assertStatus(422)->assertJsonPath('errors.password.0', 'La contraseña nueva debe ser distinta de la actual.');

        $this->actingAs($usuario)->putJson('/api/v1/auth/password', [
            'password_actual' => 'Temporal123',
            'password' => 'NuevaClave2026',
            'password_confirmation' => 'NuevaClave2027',
        ])->assertStatus(422)->assertJsonValidationErrors('password');

        $this->assertTrue(Hash::check('Temporal123', $usuario->fresh()->password));
    }

    public function test_cambiar_la_contrasena_cierra_las_otras_sesiones(): void
    {
        config(['session.driver' => 'database']);

        $usuario = User::factory()->operario()->create(['password' => 'Anterior123']);
        $otro = User::factory()->operario()->create();

        DB::table('sessions')->insert([
            ['id' => 'otra-sesion-del-usuario', 'user_id' => $usuario->id, 'ip_address' => '10.0.0.2', 'user_agent' => 'x', 'payload' => '', 'last_activity' => time()],
            ['id' => 'sesion-de-otro-usuario', 'user_id' => $otro->id, 'ip_address' => '10.0.0.3', 'user_agent' => 'x', 'payload' => '', 'last_activity' => time()],
        ]);

        $this->actingAs($usuario)->putJson('/api/v1/auth/password', [
            'password_actual' => 'Anterior123',
            'password' => 'NuevaClave2026',
            'password_confirmation' => 'NuevaClave2026',
        ])->assertOk()->assertJsonPath('message', 'Tu contraseña se cambió. Las otras sesiones abiertas con tu cuenta se cerraron.');

        $this->assertTrue(Hash::check('NuevaClave2026', $usuario->fresh()->password));
        $this->assertFalse(Hash::check('Anterior123', $usuario->fresh()->password));
        $this->assertDatabaseMissing('sessions', ['id' => 'otra-sesion-del-usuario']);
        $this->assertDatabaseHas('sessions', ['id' => 'sesion-de-otro-usuario']);
    }

    public function test_cambiar_la_contrasena_exige_sesion_y_tiene_limite(): void
    {
        $this->putJson('/api/v1/auth/password', [])->assertUnauthorized();

        $usuario = User::factory()->cliente()->create();

        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($usuario)->putJson('/api/v1/auth/password', ['password_actual' => 'mal', 'password' => 'NuevaClave2026', 'password_confirmation' => 'NuevaClave2026'])
                ->assertStatus(422);
        }

        $this->actingAs($usuario)->putJson('/api/v1/auth/password', ['password_actual' => 'mal', 'password' => 'NuevaClave2026', 'password_confirmation' => 'NuevaClave2026'])
            ->assertStatus(429);
    }

    // ---------------------------------------------------------------
    // A09: intentos fallidos en el log
    // ---------------------------------------------------------------

    public function test_los_inicios_de_sesion_fallidos_quedan_en_el_log_sin_la_contrasena(): void
    {
        Log::spy();

        User::factory()->admin()->create(['email' => 'admin@empresa.bo']);
        User::factory()->operario()->inactivo()->create(['email' => 'inactivo@empresa.bo', 'password' => 'Password123']);

        $this->postJson('/api/v1/auth/login', ['email' => 'admin@empresa.bo', 'password' => 'clave-equivocada'])->assertUnauthorized();
        $this->postJson('/api/v1/auth/login', ['email' => 'inactivo@empresa.bo', 'password' => 'Password123'])->assertForbidden();

        Log::shouldHaveReceived('warning')->withArgs(
            fn (string $mensaje, array $contexto) => $mensaje === 'Inicio de sesión fallido.'
                && $contexto['email'] === 'admin@empresa.bo'
                && $contexto['existe'] === true
                && ! in_array('clave-equivocada', $contexto, true),
        )->once();
        Log::shouldHaveReceived('warning')->withArgs(
            fn (string $mensaje) => $mensaje === 'Inicio de sesión de una cuenta desactivada.',
        )->once();
    }

    // ---------------------------------------------------------------
    // A05: cabeceras de seguridad del back
    // ---------------------------------------------------------------

    public function test_el_back_responde_con_cabeceras_de_seguridad(): void
    {
        foreach (['/api/v1/auth/me', '/api/v1/auth/google/estado', '/up'] as $ruta) {
            $respuesta = $this->get($ruta, ['Accept' => 'application/json']);

            $respuesta->assertHeader('X-Content-Type-Options', 'nosniff');
            $respuesta->assertHeader('X-Frame-Options', 'DENY');
            $respuesta->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        }

        // HSTS solo con HTTPS (en http://localhost no aplica).
        $this->get('/up')->assertHeaderMissing('Strict-Transport-Security');
        $this->get('https://localhost/up')->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }
}
