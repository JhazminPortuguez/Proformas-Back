<?php

namespace Tests\Feature;

use App\Enums\EstadoProforma;
use App\Models\Cliente;
use App\Models\Empresa;
use App\Models\Proforma;
use App\Models\ProformaItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class EmpresaConfiguracionTest extends TestCase
{
    use RefreshDatabase;

    private string $publico;

    protected function setUp(): void
    {
        parent::setUp();

        // Carpeta pública temporal: las pruebas nunca escriben en public/ real.
        $this->publico = sys_get_temp_dir().DIRECTORY_SEPARATOR.'proformas-public-'.uniqid();
        File::ensureDirectoryExists($this->publico);
        $this->app->usePublicPath($this->publico);
        // dompdf solo lee archivos dentro de su chroot (en la app, el proyecto).
        config()->set('dompdf.options.chroot', [realpath(base_path()), $this->publico]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->publico);

        parent::tearDown();
    }

    private function archivoReal(string $nombre, string $contenido): UploadedFile
    {
        $ruta = $this->publico.DIRECTORY_SEPARATOR.'subida-'.uniqid();
        File::put($ruta, $contenido);

        return new UploadedFile($ruta, $nombre, null, null, true);
    }

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    private function empresa(): Empresa
    {
        return Empresa::query()->findOrFail(1);
    }

    public function test_admin_ve_los_datos_de_la_empresa(): void
    {
        $this->empresa()->update(['nit' => '1234567', 'telefono' => '44412345', 'condiciones' => 'Pago a 30 días']);

        $this->actingAs($this->admin())->getJson('/api/v1/empresa')
            ->assertOk()
            ->assertJsonPath('id', 1)
            ->assertJsonPath('nit', '1234567')
            ->assertJsonPath('telefono', '44412345')
            ->assertJsonPath('condiciones', 'Pago a 30 días')
            ->assertJsonPath('logo_url', null);
    }

    public function test_operario_y_cliente_reciben_403_en_todo(): void
    {
        $cliente = Cliente::factory()->create();
        $usuarios = [
            User::factory()->operario()->create(),
            User::factory()->cliente()->create(['cliente_id' => $cliente->id]),
        ];

        foreach ($usuarios as $usuario) {
            $this->actingAs($usuario)->getJson('/api/v1/empresa')->assertForbidden();
            $this->actingAs($usuario)->putJson('/api/v1/empresa', ['nombre' => 'Hackeada'])->assertForbidden();
            $this->actingAs($usuario)->post('/api/v1/empresa/logo', [
                'logo' => UploadedFile::fake()->image('logo.png'),
            ], ['Accept' => 'application/json'])->assertForbidden();
            $this->actingAs($usuario)->deleteJson('/api/v1/empresa/logo')->assertForbidden();
            $this->actingAs($usuario)->getJson('/api/v1/empresa/logo')->assertForbidden();
        }

        $this->assertNotSame('Hackeada', $this->empresa()->nombre);
    }

    public function test_sin_sesion_responde_401(): void
    {
        $this->getJson('/api/v1/empresa')->assertUnauthorized();
        $this->putJson('/api/v1/empresa', ['nombre' => 'X'])->assertUnauthorized();
    }

    public function test_admin_actualiza_los_campos_reales(): void
    {
        $this->actingAs($this->admin())->putJson('/api/v1/empresa', [
            'nombre' => '  Servicios Técnicos SRL ',
            'nit' => '1020304050',
            'telefono' => '4-4412345',
            'email' => 'ventas@servicios.test',
            'direccion' => 'Av. Ayacucho 123',
            'condiciones' => "Validez 30 días.\nPago contra entrega.",
            'logo_path' => '../../.env',
        ])
            ->assertOk()
            ->assertJsonPath('nombre', 'Servicios Técnicos SRL')
            ->assertJsonPath('email', 'ventas@servicios.test');

        $empresa = $this->empresa();

        $this->assertSame('4-4412345', $empresa->telefono);
        $this->assertSame("Validez 30 días.\nPago contra entrega.", $empresa->condiciones);
        // logo_path no se cambia por PUT: solo con la subida del logo.
        $this->assertNull($empresa->logo_path);
    }

    public function test_vaciar_un_campo_opcional_lo_deja_en_null(): void
    {
        $this->empresa()->update(['telefono' => '444']);

        $this->actingAs($this->admin())->putJson('/api/v1/empresa', ['nombre' => 'Empresa', 'telefono' => '  '])
            ->assertOk()
            ->assertJsonPath('telefono', null);
    }

    public function test_valida_los_datos(): void
    {
        $this->actingAs($this->admin())->putJson('/api/v1/empresa', [
            'nombre' => '',
            'email' => 'no-es-correo',
            'nit' => str_repeat('9', 31),
            'telefono' => str_repeat('7', 31),
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['nombre', 'email', 'nit', 'telefono']);
    }

    public function test_el_cambio_se_ve_en_el_contacto_del_portal(): void
    {
        $this->actingAs($this->admin())->putJson('/api/v1/empresa', [
            'nombre' => 'Empresa Demo',
            'telefono' => '44412345',
            'email' => 'contacto@empresa.test',
        ])->assertOk();

        $cliente = Cliente::factory()->create();
        $usuario = User::factory()->cliente()->create(['cliente_id' => $cliente->id]);

        $this->actingAs($usuario)->getJson('/api/v1/portal/empresa')
            ->assertOk()
            ->assertJsonPath('telefono', '44412345')
            ->assertJsonPath('email', 'contacto@empresa.test');
    }

    public function test_sube_un_logo_png_y_reemplaza_el_anterior(): void
    {
        $admin = $this->admin();

        $primero = $this->actingAs($admin)->post('/api/v1/empresa/logo', [
            'logo' => UploadedFile::fake()->image('logo.png', 200, 80),
        ], ['Accept' => 'application/json'])->assertOk();

        $rutaPrimera = $this->empresa()->logo_path;
        $this->assertMatchesRegularExpression('#^logos/empresa-1-[a-z0-9]{12}\.png$#', $rutaPrimera);
        $this->assertFileExists(public_path($rutaPrimera));
        $this->assertStringStartsWith('/api/v1/empresa/logo?v=', $primero->json('logo_url'));

        $this->actingAs($admin)->post('/api/v1/empresa/logo', [
            'logo' => UploadedFile::fake()->image('logo.jpg', 200, 80),
        ], ['Accept' => 'application/json'])->assertOk();

        $rutaNueva = $this->empresa()->logo_path;
        $this->assertStringEndsWith('.jpg', $rutaNueva);
        $this->assertFileExists(public_path($rutaNueva));
        $this->assertFileDoesNotExist(public_path($rutaPrimera));

        $this->actingAs($admin)->get('/api/v1/empresa/logo')
            ->assertOk()
            ->assertHeader('content-type', 'image/jpeg');
    }

    public function test_rechaza_logos_que_no_son_png_ni_jpg_o_pesan_mas_de_1_mb(): void
    {
        $admin = $this->admin();
        $casos = [
            'pdf' => UploadedFile::fake()->create('logo.pdf', 10, 'application/pdf'),
            'gif' => UploadedFile::fake()->image('logo.gif'),
            // Extensión .png pero el contenido no es una imagen. Un archivo
            // real (no fake), para que el tipo se lea del contenido.
            'disfrazado' => $this->archivoReal('logo.png', 'esto no es una imagen'),
            'grande' => UploadedFile::fake()->image('logo.png')->size(1025),
        ];

        foreach ($casos as $caso => $archivo) {
            $respuesta = $this->actingAs($admin)->post('/api/v1/empresa/logo', ['logo' => $archivo], ['Accept' => 'application/json']);
            $this->assertSame(422, $respuesta->status(), "El caso {$caso} debía rechazarse");
            $respuesta->assertJsonValidationErrors('logo');
        }

        $this->actingAs($admin)->post('/api/v1/empresa/logo', [], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['logo' => 'Elige una imagen para el logo.']);

        $this->assertNull($this->empresa()->logo_path);
        $this->assertDirectoryDoesNotExist(public_path('logos'));
    }

    public function test_acepta_un_logo_de_justo_1_mb(): void
    {
        $this->actingAs($this->admin())->post('/api/v1/empresa/logo', [
            'logo' => UploadedFile::fake()->image('logo.png')->size(1024),
        ], ['Accept' => 'application/json'])->assertOk();
    }

    public function test_quitar_el_logo_borra_el_archivo(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post('/api/v1/empresa/logo', [
            'logo' => UploadedFile::fake()->image('logo.png'),
        ], ['Accept' => 'application/json'])->assertOk();
        $ruta = $this->empresa()->logo_path;

        $this->actingAs($admin)->deleteJson('/api/v1/empresa/logo')
            ->assertOk()
            ->assertJsonPath('logo_url', null);

        $this->assertNull($this->empresa()->logo_path);
        $this->assertFileDoesNotExist(public_path($ruta));
        $this->actingAs($admin)->getJson('/api/v1/empresa/logo')->assertNotFound();
    }

    public function test_quitar_el_logo_nunca_borra_archivos_fuera_de_la_carpeta(): void
    {
        File::put(public_path('index.php'), '<?php // no borrar');
        $this->empresa()->update(['logo_path' => 'index.php']);

        $this->actingAs($this->admin())->deleteJson('/api/v1/empresa/logo')->assertOk();

        $this->assertFileExists(public_path('index.php'));
    }

    public function test_el_logo_aparece_en_el_pdf_de_la_proforma(): void
    {
        $admin = $this->admin();
        $proforma = Proforma::factory()->create(['estado' => EstadoProforma::Enviada]);
        ProformaItem::factory()->servicio()->create(['proforma_id' => $proforma->id]);

        $sinLogo = $this->actingAs($admin)->get("/api/v1/proformas/{$proforma->id}/pdf")->assertOk()->getContent();
        $this->assertStringNotContainsString('/Subtype /Image', $sinLogo);

        $this->actingAs($admin)->post('/api/v1/empresa/logo', [
            'logo' => UploadedFile::fake()->image('logo.png', 300, 100),
        ], ['Accept' => 'application/json'])->assertOk();

        $conLogo = $this->actingAs($admin)->get("/api/v1/proformas/{$proforma->id}/pdf")->assertOk()->getContent();
        $this->assertStringContainsString('/Subtype /Image', $conLogo);
    }
}
