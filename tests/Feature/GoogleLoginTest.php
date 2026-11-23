<?php

namespace Tests\Feature;

use App\Enums\EstadoCliente;
use App\Enums\Rol;
use App\Models\Cliente;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use Laravel\Socialite\Two\User as UsuarioSocialite;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * Ronda B, parte 2: "Continuar con Google" con Socialite SIMULADO. Ninguna
 * prueba usa cuentas ni servidores reales de Google.
 */
class GoogleLoginTest extends TestCase
{
    use RefreshDatabase;

    private const FRONT = 'https://proformas.test';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.frontend_url' => self::FRONT,
            'mail.default' => 'array',
            'services.google.client_id' => 'id-de-prueba.apps.googleusercontent.com',
            'services.google.client_secret' => 'secreto-de-prueba',
            'services.google.redirect' => self::FRONT.'/api/v1/auth/google/callback',
        ]);
    }

    /** Google (simulado) devuelve esta cuenta en el callback. */
    private function google(string $email, string $nombre = 'Ana Google', bool $verificado = true, string $id = '1098765'): void
    {
        $usuario = (new UsuarioSocialite)
            ->setRaw(['sub' => $id, 'email' => $email, 'email_verified' => $verificado, 'name' => $nombre])
            ->map(['id' => $id, 'name' => $nombre, 'email' => $email]);

        $proveedor = Mockery::mock(GoogleProvider::class);
        $proveedor->shouldReceive('user')->andReturn($usuario);

        Socialite::shouldReceive('driver')->with('google')->andReturn($proveedor);
    }

    private function volverDeGoogle(string $consulta = '?state=x&code=y')
    {
        return $this->get('/api/v1/auth/google/callback'.$consulta);
    }

    public function test_sin_credenciales_el_boton_no_aparece_y_las_rutas_dan_404(): void
    {
        $this->getJson('/api/v1/auth/google/estado')->assertOk()->assertExactJson(['habilitado' => true]);

        config(['services.google.client_id' => null]);

        $this->getJson('/api/v1/auth/google/estado')->assertOk()->assertExactJson(['habilitado' => false]);
        $this->get('/api/v1/auth/google/redirect')->assertNotFound();
        $this->get('/api/v1/auth/google/callback?code=x')->assertNotFound();
    }

    public function test_el_boton_lleva_a_google_con_el_client_id_y_el_callback_del_front(): void
    {
        $destino = $this->get('/api/v1/auth/google/redirect')->assertRedirect()->headers->get('Location');

        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/auth?', $destino);
        $this->assertStringContainsString('client_id=id-de-prueba.apps.googleusercontent.com', $destino);
        $this->assertStringContainsString('redirect_uri='.urlencode(self::FRONT.'/api/v1/auth/google/callback'), $destino);
        $this->assertStringContainsString('state=', $destino);
    }

    public function test_usuario_existente_activo_entra_y_queda_verificado(): void
    {
        $cliente = Cliente::factory()->create(['email' => 'cliente@gmail.com']);
        $usuario = User::factory()->cliente()->unverified()->create([
            'email' => 'cliente@gmail.com',
            'cliente_id' => $cliente->id,
        ]);
        $this->google('Cliente@Gmail.com');

        $this->volverDeGoogle()->assertRedirect(self::FRONT.'/portal');

        $this->assertAuthenticatedAs($usuario);
        $usuario->refresh();
        $this->assertTrue($usuario->hasVerifiedEmail());
        $this->assertSame('1098765', $usuario->google_id);
        $this->assertFalse($usuario->datos_pendientes);
        $this->assertSame(1, User::query()->count());
    }

    public function test_el_personal_entra_con_google_a_su_panel(): void
    {
        $admin = User::factory()->admin()->create(['email' => 'admin@empresa.bo']);
        $this->google('admin@empresa.bo');

        $this->volverDeGoogle()->assertRedirect(self::FRONT.'/dashboard');
        $this->assertAuthenticatedAs($admin);
    }

    public function test_correo_nuevo_crea_un_cliente_prospecto_que_completa_sus_datos_antes_de_solicitar(): void
    {
        $this->google('nueva@gmail.com', 'María Nueva');

        $this->volverDeGoogle()->assertRedirect(self::FRONT.'/portal');

        $usuario = User::query()->where('email', 'nueva@gmail.com')->firstOrFail();
        $this->assertAuthenticatedAs($usuario);
        $this->assertSame(Rol::Cliente, $usuario->rol);
        $this->assertSame('María Nueva', $usuario->name);
        $this->assertTrue($usuario->hasVerifiedEmail());
        $this->assertTrue($usuario->datos_pendientes);
        $this->assertSame(EstadoCliente::Prospecto, $usuario->cliente->estado);
        $this->assertSame('María Nueva', $usuario->cliente->nombre_razon);

        // (201 y no 200 solo en la prueba: el mismo proceso conserva el usuario recién creado.)
        $this->getJson('/api/v1/auth/me')->assertSuccessful()->assertJsonPath('datos_pendientes', true);

        $solicitud = [
            'descripcion' => 'Necesito mantenimiento',
            'items' => [['descripcion' => 'Mantenimiento', 'cantidad' => 1]],
        ];

        // Sin teléfono ni NIT/CI no envía solicitudes.
        $this->postJson('/api/v1/portal/solicitudes', $solicitud)
            ->assertForbidden()
            ->assertJsonPath('codigo', 'datos_pendientes');

        $this->putJson('/api/v1/portal/mis-datos', ['telefono' => '', 'nit_ci' => 'x'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['telefono', 'nit_ci']);

        $this->putJson('/api/v1/portal/mis-datos', ['telefono' => '+591 71234567', 'nit_ci' => '7654321 LP'])
            ->assertSuccessful()
            ->assertJsonPath('datos_pendientes', false)
            ->assertJsonPath('cliente.telefono', '+591 71234567')
            ->assertJsonPath('cliente.nit_ci', '7654321 LP');

        $this->postJson('/api/v1/portal/solicitudes', $solicitud)->assertCreated();

        // Es una sola vez: después, sus datos los cambia la empresa.
        $this->putJson('/api/v1/portal/mis-datos', ['telefono' => '70000000', 'nit_ci' => '9999999'])
            ->assertForbidden();
    }

    public function test_completar_datos_no_revela_a_otro_cliente_con_el_mismo_nit(): void
    {
        Cliente::factory()->create(['nit_ci' => '1234567', 'nombre_razon' => 'Constructora Secreta']);
        $this->google('nueva@gmail.com');
        $this->volverDeGoogle();

        $respuesta = $this->putJson('/api/v1/portal/mis-datos', ['telefono' => '71234567', 'nit_ci' => '123 4567'])
            ->assertStatus(422)
            ->assertJsonPath('errors.nit_ci.0', 'Ese NIT/CI ya está registrado con otro cliente. Comunícate con la empresa para vincular tu cuenta.');

        $this->assertStringNotContainsString('Constructora Secreta', $respuesta->getContent());
        $this->assertTrue(User::query()->where('email', 'nueva@gmail.com')->value('datos_pendientes'));
    }

    public function test_usuario_inactivo_no_entra(): void
    {
        User::factory()->operario()->inactivo()->create(['email' => 'inactivo@empresa.bo']);
        $this->google('inactivo@empresa.bo');

        $this->volverDeGoogle()->assertRedirect(self::FRONT.'/login?google=inactivo');
        $this->assertGuest();
    }

    public function test_el_superusuario_no_entra_con_google(): void
    {
        User::factory()->superusuario()->create(['email' => 'soporte@empresa.bo']);
        $this->google('soporte@empresa.bo');

        $this->volverDeGoogle()->assertRedirect(self::FRONT.'/login?google=superusuario');
        $this->assertGuest();
    }

    public function test_correo_no_verificado_por_google_no_entra(): void
    {
        $this->google('sin.verificar@gmail.com', verificado: false);

        $this->volverDeGoogle()->assertRedirect(self::FRONT.'/login?google=sin-verificar');
        $this->assertGuest();
        $this->assertSame(0, User::query()->count());
    }

    public function test_correo_de_un_cliente_sin_usuario_pide_el_acceso_a_la_empresa(): void
    {
        Cliente::factory()->create(['email' => 'compras@cliente.bo']);
        $this->google('Compras@Cliente.bo');

        $this->volverDeGoogle()->assertRedirect(self::FRONT.'/login?google=cliente-existente');
        $this->assertGuest();
        $this->assertSame(0, User::query()->count());
    }

    public function test_cancelar_en_google_o_un_state_invalido_vuelven_al_login(): void
    {
        $this->volverDeGoogle('?error=access_denied')->assertRedirect(self::FRONT.'/login?google=cancelado');

        $proveedor = Mockery::mock(GoogleProvider::class);
        $proveedor->shouldReceive('user')->andThrow(new RuntimeException('Invalid state'));
        Socialite::shouldReceive('driver')->with('google')->andReturn($proveedor);

        $this->volverDeGoogle()->assertRedirect(self::FRONT.'/login?google=error');
        $this->assertGuest();
    }
}
