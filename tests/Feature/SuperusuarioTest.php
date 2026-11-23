<?php

namespace Tests\Feature;

use App\Enums\Rol;
use App\Models\Cliente;
use App\Models\Empresa;
use App\Models\OrdenServicio;
use App\Models\User;
use App\Support\ErrorCorreo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

/**
 * Ronda B, parte 1: el rol técnico "superusuario", distinto del
 * administrador (negocio).
 */
class SuperusuarioTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['mail.default' => 'array']);
    }

    // ---------------------------------------------------------------
    // Comando de instalación
    // ---------------------------------------------------------------

    public function test_el_comando_pide_los_datos_y_crea_el_superusuario(): void
    {
        $this->artisan('sistema:crear-superusuario')
            ->expectsQuestion('Nombre completo', 'Soporte Técnico')
            ->expectsQuestion('Correo electrónico', 'Soporte@Example.com')
            ->expectsQuestion('Contraseña (mínimo 8 caracteres, con letras y números)', 'Clave1234')
            ->expectsQuestion('Repite la contraseña', 'Clave1234')
            ->expectsOutputToContain('Superusuario creado: Soporte Técnico <soporte@example.com>')
            ->assertSuccessful();

        $usuario = User::query()->where('email', 'soporte@example.com')->firstOrFail();
        $this->assertSame(Rol::Superusuario, $usuario->rol);
        $this->assertTrue($usuario->activo);
        $this->assertTrue($usuario->hasVerifiedEmail());
        $this->assertTrue(Hash::check('Clave1234', $usuario->password));
    }

    public function test_el_comando_valida_correo_repetido_contrasena_debil_y_confirmacion(): void
    {
        User::factory()->admin()->create(['email' => 'ya@existe.bo']);

        $this->artisan('sistema:crear-superusuario', ['--nombre' => 'X', '--email' => 'ya@existe.bo'])
            ->expectsQuestion('Contraseña (mínimo 8 caracteres, con letras y números)', 'Clave1234')
            ->expectsOutputToContain('Ya existe un usuario con ese correo.')
            ->assertFailed();

        $this->artisan('sistema:crear-superusuario', ['--nombre' => 'X', '--email' => 'nuevo@sistema.bo'])
            ->expectsQuestion('Contraseña (mínimo 8 caracteres, con letras y números)', 'corta')
            ->assertFailed();

        $this->artisan('sistema:crear-superusuario', ['--nombre' => 'X', '--email' => 'nuevo@sistema.bo'])
            ->expectsQuestion('Contraseña (mínimo 8 caracteres, con letras y números)', 'Clave1234')
            ->expectsQuestion('Repite la contraseña', 'Clave9999')
            ->expectsOutputToContain('Las contraseñas no coinciden.')
            ->assertFailed();

        $this->assertSame(0, User::query()->where('rol', Rol::Superusuario)->count());
    }

    public function test_el_comando_genera_una_contrasena_temporal(): void
    {
        $this->artisan('sistema:crear-superusuario', [
            '--nombre' => 'Soporte Técnico',
            '--email' => 'soporte@example.com',
            '--password-temporal' => true,
        ])->expectsOutputToContain('Contraseña temporal:')->assertSuccessful();

        $this->assertSame(Rol::Superusuario, User::query()->where('email', 'soporte@example.com')->value('rol'));
    }

    // ---------------------------------------------------------------
    // Permisos de cada rol
    // ---------------------------------------------------------------

    public function test_nadie_crea_ni_asciende_a_superusuario_desde_la_pantalla(): void
    {
        $superusuario = User::factory()->superusuario()->create();
        $admin = User::factory()->admin()->create();
        $operario = User::factory()->operario()->create();

        foreach ([$superusuario, $admin] as $actor) {
            $this->actingAs($actor)->postJson('/api/v1/users', [
                'name' => 'Intruso',
                'email' => 'intruso'.$actor->id.'@sistema.bo',
                'password' => 'Password123!',
                'rol' => 'superusuario',
            ])->assertForbidden()->assertJsonPath('message', fn (string $m) => str_contains($m, 'sistema:crear-superusuario'));
        }

        $this->actingAs($superusuario)->putJson("/api/v1/users/{$operario->id}", ['rol' => 'superusuario'])->assertForbidden();
        $this->assertSame(Rol::Operario, $operario->fresh()->rol);
        $this->assertSame(1, User::query()->where('rol', Rol::Superusuario)->count());
    }

    public function test_el_superusuario_gestiona_administradores_y_operarios(): void
    {
        $superusuario = User::factory()->superusuario()->create();
        User::factory()->admin()->create();
        $cliente = Cliente::factory()->create();
        $usuarioCliente = User::factory()->cliente()->create(['cliente_id' => $cliente->id]);

        $nuevoAdmin = $this->actingAs($superusuario)->postJson('/api/v1/users', [
            'name' => 'Admin Nuevo',
            'email' => 'admin.nuevo@sistema.bo',
            'password' => 'Password123!',
            'rol' => 'admin',
        ])->assertCreated()->assertJsonPath('rol', 'admin')->json('id');

        $this->actingAs($superusuario)->putJson("/api/v1/users/{$nuevoAdmin}", ['name' => 'Admin Renombrado'])->assertOk();
        $this->actingAs($superusuario)->putJson("/api/v1/users/{$nuevoAdmin}", ['activo' => false])
            ->assertOk()->assertJsonPath('activo', false);

        $this->actingAs($superusuario)->postJson('/api/v1/users', [
            'name' => 'Operario Nuevo',
            'email' => 'operario.nuevo@sistema.bo',
            'password' => 'Password123!',
            'rol' => 'operario',
        ])->assertCreated();

        // Los usuarios cliente no son de su área: ni los crea ni los ve.
        $this->actingAs($superusuario)->postJson('/api/v1/users', [
            'name' => 'Cliente',
            'email' => 'cliente.nuevo@sistema.bo',
            'password' => 'Password123!',
            'rol' => 'cliente',
            'cliente_id' => $cliente->id,
        ])->assertForbidden();
        $this->actingAs($superusuario)->putJson("/api/v1/users/{$usuarioCliente->id}", ['activo' => false])->assertForbidden();

        $roles = collect($this->actingAs($superusuario)->getJson('/api/v1/users?per_page=100')->assertOk()->json('data'))
            ->pluck('rol')->unique()->sort()->values()->all();
        $this->assertSame(['admin', 'operario', 'superusuario'], $roles);
    }

    public function test_el_admin_gestiona_operarios_y_clientes_pero_no_administradores_ni_superusuarios(): void
    {
        $superusuario = User::factory()->superusuario()->create();
        $admin = User::factory()->admin()->create();
        $otroAdmin = User::factory()->admin()->create();
        $operario = User::factory()->operario()->create();

        $this->actingAs($admin)->postJson('/api/v1/users', [
            'name' => 'Otro admin',
            'email' => 'otro.admin@sistema.bo',
            'password' => 'Password123!',
            'rol' => 'admin',
        ])->assertForbidden()->assertJsonPath(
            'message',
            'No puedes crear usuarios con el rol Administrador. Como administrador solo puedes crear operario o cliente.',
        );

        $this->actingAs($admin)->putJson("/api/v1/users/{$otroAdmin->id}", ['activo' => false])->assertForbidden();
        $this->actingAs($admin)->putJson("/api/v1/users/{$operario->id}", ['rol' => 'admin'])->assertForbidden();
        $this->actingAs($admin)->deleteJson("/api/v1/users/{$otroAdmin->id}")->assertForbidden();
        $this->actingAs($admin)->putJson("/api/v1/users/{$superusuario->id}", ['activo' => false])->assertForbidden();
        $this->actingAs($admin)->getJson("/api/v1/users/{$superusuario->id}")->assertForbidden();

        // Operarios: sí.
        $this->actingAs($admin)->putJson("/api/v1/users/{$operario->id}", ['activo' => false])->assertOk();
        $this->actingAs($admin)->postJson('/api/v1/users', [
            'name' => 'Operaria',
            'email' => 'operaria@sistema.bo',
            'password' => 'Password123!',
            'rol' => 'operario',
        ])->assertCreated();

        // No ve a los superusuarios en el listado; a los administradores sí.
        $roles = collect($this->actingAs($admin)->getJson('/api/v1/users?per_page=100')->assertOk()->json('data'))->pluck('rol');
        $this->assertNotContains('superusuario', $roles);
        $this->assertContains('admin', $roles);

        $this->assertTrue($otroAdmin->fresh()->activo);
        $this->assertTrue($superusuario->fresh()->activo);
    }

    public function test_el_superusuario_no_participa_en_la_operacion(): void
    {
        $superusuario = User::factory()->superusuario()->create();

        foreach (['/api/v1/dashboard', '/api/v1/clientes', '/api/v1/proformas', '/api/v1/ordenes', '/api/v1/servicios',
            '/api/v1/inventario', '/api/v1/cuentas-por-cobrar', '/api/v1/solicitudes',
            '/api/v1/reportes/ordenes?desde=2026-11-01&hasta=2026-11-30'] as $ruta) {
            $this->actingAs($superusuario)->getJson($ruta)->assertForbidden();
        }

        $this->actingAs($superusuario)->getJson('/api/v1/portal/resumen')->assertForbidden();

        // No se le pueden asignar órdenes (no es operario).
        $admin = User::factory()->admin()->create();
        $orden = OrdenServicio::factory()->create();
        $this->actingAs($admin)->postJson("/api/v1/ordenes/{$orden->id}/asignar", [
            'operario_id' => $superusuario->id,
            'fecha_programada' => now()->addDay()->toDateString(),
        ])->assertStatus(422)->assertJsonPath('errors.operario_id.0', 'El usuario seleccionado no tiene rol de operario.');

        // Tampoco aparece en el listado de operarios (asignación y reportes).
        $ids = collect($this->actingAs($admin)->getJson('/api/v1/users?rol=operario&per_page=100')->json('data'))->pluck('id');
        $this->assertNotContains($superusuario->id, $ids);
    }

    public function test_parametros_los_lee_el_personal_y_solo_los_cambia_el_superusuario(): void
    {
        $superusuario = User::factory()->superusuario()->create();
        $admin = User::factory()->admin()->create();
        $operario = User::factory()->operario()->create();
        $cliente = Cliente::factory()->create();
        $usuarioCliente = User::factory()->cliente()->create(['cliente_id' => $cliente->id]);

        // Los valores de siempre: 13 % y sin validez.
        $this->actingAs($operario)->getJson('/api/v1/parametros')->assertOk()
            ->assertExactJson(['impuesto_por_defecto' => '13.00', 'dias_validez_por_defecto' => null]);

        $this->actingAs($superusuario)->putJson('/api/v1/parametros', ['impuesto_por_defecto' => 15.5, 'dias_validez_por_defecto' => 30])
            ->assertOk()->assertExactJson(['impuesto_por_defecto' => '15.50', 'dias_validez_por_defecto' => 30]);
        $this->actingAs($admin)->getJson('/api/v1/parametros')->assertOk()->assertJsonPath('dias_validez_por_defecto', 30);

        foreach ([$admin, $operario, $usuarioCliente] as $usuario) {
            $this->actingAs($usuario)->putJson('/api/v1/parametros', ['impuesto_por_defecto' => 0])->assertForbidden();
        }
        $this->actingAs($usuarioCliente)->getJson('/api/v1/parametros')->assertForbidden();

        $this->actingAs($superusuario)->putJson('/api/v1/parametros', ['impuesto_por_defecto' => 101, 'dias_validez_por_defecto' => 0])
            ->assertStatus(422)
            ->assertJsonPath('errors.impuesto_por_defecto.0', 'El impuesto debe estar entre 0 y 100 %.')
            ->assertJsonPath('errors.dias_validez_por_defecto.0', 'Los días de validez deben estar entre 1 y 365.');
        $this->actingAs($superusuario)->putJson('/api/v1/parametros', ['impuesto_por_defecto' => 1.234])
            ->assertStatus(422)->assertJsonPath('errors.impuesto_por_defecto.0', 'El impuesto admite como máximo 2 decimales.');

        // Sin días: la validez vuelve a quedar en blanco.
        $this->actingAs($superusuario)->putJson('/api/v1/parametros', ['impuesto_por_defecto' => 13, 'dias_validez_por_defecto' => null])
            ->assertOk()->assertJsonPath('dias_validez_por_defecto', null);

        $this->assertSame('13.00', (string) Empresa::query()->findOrFail(1)->impuesto_por_defecto);
    }

    // ---------------------------------------------------------------
    // Último superusuario
    // ---------------------------------------------------------------

    public function test_no_se_desactiva_ni_se_quita_ni_se_elimina_al_ultimo_superusuario_activo(): void
    {
        $uno = User::factory()->superusuario()->create();
        $dos = User::factory()->superusuario()->create();
        User::factory()->superusuario()->create(['activo' => false]);

        // Con dos activos, uno puede desactivar al otro.
        $this->actingAs($uno)->putJson("/api/v1/users/{$dos->id}", ['activo' => false])->assertOk();

        // Queda uno solo activo: nadie puede desactivarlo, quitarle el rol ni eliminarlo.
        $dos->forceFill(['activo' => true]);
        $this->actingAs($dos)->putJson("/api/v1/users/{$uno->id}", ['activo' => false])
            ->assertStatus(422)
            ->assertJsonPath('message', 'No se puede quitar el rol ni desactivar al último superusuario activo.');
        $this->actingAs($dos)->putJson("/api/v1/users/{$uno->id}", ['rol' => 'admin'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'No se puede quitar el rol ni desactivar al último superusuario activo.');
        $this->actingAs($dos)->deleteJson("/api/v1/users/{$uno->id}")
            ->assertStatus(422)
            ->assertJsonPath('errors.rol.0', 'No se puede eliminar al último superusuario activo.');

        $this->assertTrue($uno->fresh()->activo);
        $this->assertSame(Rol::Superusuario, $uno->fresh()->rol);
    }

    // ---------------------------------------------------------------
    // Estado del sistema y correo de prueba
    // ---------------------------------------------------------------

    public function test_estado_del_sistema_sin_la_contrasena_del_correo(): void
    {
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => 'smtp.gmail.com',
            'mail.mailers.smtp.port' => 587,
            'mail.mailers.smtp.username' => 'empresa@gmail.com',
            'mail.mailers.smtp.password' => 'abcd efgh ijkl mnop',
            'mail.from.address' => 'empresa@gmail.com',
            'app.version' => '1.4.0',
        ]);

        $superusuario = User::factory()->superusuario()->create();

        $respuesta = $this->actingAs($superusuario)->getJson('/api/v1/sistema/estado')->assertOk()
            ->assertJsonPath('app.version', '1.4.0')
            ->assertJsonPath('correo.mailer', 'smtp')
            ->assertJsonPath('correo.configurado', true)
            ->assertJsonPath('correo.host', 'smtp.gmail.com')
            ->assertJsonPath('correo.usuario', 'empresa@gmail.com')
            ->assertJsonPath('correo.password_configurada', true)
            ->assertJsonPath('correo.faltan', [])
            ->assertJsonPath('migraciones.pendientes', 0)
            ->assertJsonPath('migraciones.aplicadas', fn (int $n) => $n > 0);

        $this->assertStringNotContainsString('abcd efgh', $respuesta->getContent());
        $this->assertStringNotContainsString('abcdefgh', $respuesta->getContent());

        // Sin contraseña: dice qué falta.
        config(['mail.mailers.smtp.password' => null]);
        $this->actingAs($superusuario)->getJson('/api/v1/sistema/estado')
            ->assertJsonPath('correo.configurado', false)
            ->assertJsonPath('correo.faltan', ['MAIL_PASSWORD']);

        foreach ([User::factory()->admin()->create(), User::factory()->operario()->create()] as $usuario) {
            $this->actingAs($usuario)->getJson('/api/v1/sistema/estado')->assertForbidden();
            $this->actingAs($usuario)->postJson('/api/v1/sistema/correo-prueba')->assertForbidden();
        }
    }

    public function test_correo_de_prueba_a_su_propio_email_con_el_mailer_de_prueba(): void
    {
        $superusuario = User::factory()->superusuario()->create(['email' => 'soporte@example.com', 'name' => 'Soporte']);

        $this->actingAs($superusuario)->postJson('/api/v1/sistema/correo-prueba')
            ->assertOk()
            ->assertJsonPath('enviado', true)
            ->assertJsonPath('real', false);

        $mensajes = app('mailer')->getSymfonyTransport()->messages();
        $this->assertCount(1, $mensajes);
        $correo = $mensajes[0]->getOriginalMessage();
        $this->assertSame('Prueba de correo – Proformas', $correo->getSubject());
        $this->assertSame('soporte@example.com', $correo->getTo()[0]->getAddress());

        // Límite: 3 por minuto.
        $this->actingAs($superusuario)->postJson('/api/v1/sistema/correo-prueba')->assertOk();
        $this->actingAs($superusuario)->postJson('/api/v1/sistema/correo-prueba')->assertOk();
        $this->actingAs($superusuario)->postJson('/api/v1/sistema/correo-prueba')->assertStatus(429);
    }

    public function test_si_el_correo_de_prueba_falla_explica_el_error_sin_la_contrasena(): void
    {
        // Un servidor que no existe: la conexión falla al instante.
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => '127.0.0.1',
            'mail.mailers.smtp.port' => 1,
            'mail.mailers.smtp.timeout' => 2,
            'mail.mailers.smtp.password' => 'clave secreta',
        ]);
        $superusuario = User::factory()->superusuario()->create();

        $respuesta = $this->actingAs($superusuario)->postJson('/api/v1/sistema/correo-prueba')
            ->assertStatus(502)
            ->assertJsonPath('enviado', false)
            ->assertJsonPath('message', fn (string $m) => str_starts_with($m, 'No se pudo enviar el correo de prueba. No se pudo conectar'));
        $this->assertStringNotContainsString('clave secreta', $respuesta->getContent());

        // Si el servidor repitiera la contraseña, no llega ni al log ni a la pantalla.
        $error = new RuntimeException('535 Username and Password not accepted for clave secreta / clavesecreta');
        $this->assertStringNotContainsString('clave', ErrorCorreo::limpiar($error->getMessage()));
        $this->assertStringContainsString('contraseña de aplicación', ErrorCorreo::explicar($error));
    }
}
