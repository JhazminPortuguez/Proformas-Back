<?php

namespace Tests\Feature;

use App\Enums\EstadoProforma;
use App\Enums\EstadoSolicitud;
use App\Models\Cliente;
use App\Models\CuentaPorCobrar;
use App\Models\Empresa;
use App\Models\OrdenServicio;
use App\Models\PagoCuenta;
use App\Models\Producto;
use App\Models\Proforma;
use App\Models\ProformaItem;
use App\Models\Servicio;
use App\Models\SolicitudCotizacion;
use App\Models\SolicitudItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class PortalTest extends TestCase
{
    use RefreshDatabase;

    private Cliente $clienteA;

    private Cliente $clienteB;

    private User $usuarioA;

    private User $usuarioB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clienteA = Cliente::factory()->create(['nombre_razon' => 'Cliente A']);
        $this->clienteB = Cliente::factory()->create(['nombre_razon' => 'Cliente B']);
        $this->usuarioA = User::factory()->cliente()->create(['cliente_id' => $this->clienteA->id]);
        $this->usuarioB = User::factory()->cliente()->create(['cliente_id' => $this->clienteB->id]);
    }

    private function proforma(Cliente $cliente, EstadoProforma $estado, array $extra = []): Proforma
    {
        $proforma = Proforma::factory()->create([
            'cliente_id' => $cliente->id,
            'estado' => $estado,
            'enviada_at' => $estado === EstadoProforma::Borrador ? null : now(),
            'total' => 150,
            ...$extra,
        ]);

        ProformaItem::factory()->servicio()->create([
            'proforma_id' => $proforma->id,
            'nombre' => 'Instalación eléctrica',
        ]);
        ProformaItem::factory()->servicio()->create([
            'proforma_id' => $proforma->id,
            'nombre' => 'Revisión de tablero',
        ]);

        return $proforma;
    }

    // ---------------------------------------------------------------
    // Acceso
    // ---------------------------------------------------------------

    public function test_sin_sesion_responde_401(): void
    {
        $this->getJson('/api/v1/portal/resumen')->assertUnauthorized();
        $this->getJson('/api/v1/portal/proformas')->assertUnauthorized();
        $this->postJson('/api/v1/portal/solicitudes', [])->assertUnauthorized();
    }

    public function test_admin_y_operario_no_usan_el_portal(): void
    {
        foreach ([User::factory()->admin()->create(), User::factory()->operario()->create()] as $interno) {
            foreach (['resumen', 'empresa', 'servicios', 'solicitudes', 'proformas'] as $ruta) {
                $this->actingAs($interno)->getJson("/api/v1/portal/{$ruta}")->assertForbidden();
            }

            $this->actingAs($interno)->postJson('/api/v1/portal/solicitudes', [
                'descripcion' => 'X',
                'items' => [['descripcion' => 'Y', 'cantidad' => 1]],
            ])->assertForbidden();
        }

        $this->assertSame(0, SolicitudCotizacion::count());
    }

    public function test_cliente_sin_cliente_vinculado_recibe_403(): void
    {
        $huerfano = User::factory()->cliente()->create(['cliente_id' => null]);

        $this->actingAs($huerfano)->getJson('/api/v1/portal/proformas')->assertForbidden();
        $this->actingAs($huerfano)->getJson('/api/v1/portal/resumen')->assertForbidden();
    }

    public function test_cliente_inactivo_queda_bloqueado(): void
    {
        $inactivo = User::factory()->cliente()->inactivo()->create(['cliente_id' => $this->clienteA->id]);

        $this->actingAs($inactivo)->getJson('/api/v1/portal/proformas')
            ->assertForbidden()
            ->assertJsonPath('message', 'Tu cuenta está desactivada.');
    }

    /**
     * Recorre TODAS las rutas internas de la API: el cliente recibe 403 en
     * cada una, incluidas aceptar y rechazar proformas.
     */
    public function test_cliente_recibe_403_en_todas_las_rutas_internas(): void
    {
        $proforma = $this->proforma($this->clienteA, EstadoProforma::Enviada);
        $orden = OrdenServicio::factory()->create(['cliente_id' => $this->clienteA->id]);
        $cuenta = CuentaPorCobrar::factory()->create(['cliente_id' => $this->clienteA->id]);
        $pago = PagoCuenta::factory()->create(['cuenta_id' => $cuenta->id]);

        $ids = [
            'cliente' => $this->clienteA->id,
            'producto' => Producto::factory()->create()->id,
            'proforma' => $proforma->id,
            'orden' => $orden->id,
            'ordene' => $orden->id,
            'servicio' => Servicio::factory()->create()->id,
            'solicitud' => SolicitudCotizacion::factory()->create(['cliente_id' => $this->clienteA->id])->id,
            'solicitude' => SolicitudCotizacion::factory()->create(['cliente_id' => $this->clienteA->id])->id,
            'user' => $this->usuarioB->id,
            'cuenta' => $cuenta->id,
            'pago' => $pago->id,
        ];

        $revisadas = 0;

        foreach (Route::getRoutes() as $ruta) {
            $uri = $ruta->uri();

            if (! str_starts_with($uri, 'api/v1/') || str_starts_with($uri, 'api/v1/auth/') || str_starts_with($uri, 'api/v1/portal/')) {
                continue;
            }

            $url = preg_replace_callback('/\{(\w+)\??\}/', function (array $m) use ($ids, $uri): string {
                $this->assertArrayHasKey($m[1], $ids, "Falta un id de prueba para {{$m[1]}} en {$uri}");

                return (string) $ids[$m[1]];
            }, $uri);

            foreach (array_diff($ruta->methods(), ['HEAD']) as $metodo) {
                $this->actingAs($this->usuarioA)
                    ->json($metodo, '/'.$url)
                    ->assertStatus(403, "{$metodo} /{$url} debería responder 403 al cliente");
                $revisadas++;
            }
        }

        $this->assertGreaterThan(40, $revisadas);
        $this->assertSame(EstadoProforma::Enviada, $proforma->fresh()->estado);
    }

    // ---------------------------------------------------------------
    // Aislamiento entre clientes
    // ---------------------------------------------------------------

    public function test_listados_solo_traen_datos_del_propio_cliente(): void
    {
        $solicitudA = SolicitudCotizacion::factory()->create(['cliente_id' => $this->clienteA->id]);
        SolicitudCotizacion::factory()->create(['cliente_id' => $this->clienteB->id]);
        $proformaA = $this->proforma($this->clienteA, EstadoProforma::Enviada);
        $this->proforma($this->clienteB, EstadoProforma::Enviada);
        $this->proforma($this->clienteB, EstadoProforma::Aceptada);

        $this->actingAs($this->usuarioA)->getJson('/api/v1/portal/solicitudes')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $solicitudA->id)
            ->assertJsonPath('meta.total', 1);

        $this->actingAs($this->usuarioA)->getJson('/api/v1/portal/proformas')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $proformaA->id);

        $this->actingAs($this->usuarioA)->getJson('/api/v1/portal/resumen')
            ->assertOk()
            ->assertJsonPath('solicitudes.PENDIENTE', 1)
            ->assertJsonPath('proformas.ENVIADA', 1)
            ->assertJsonPath('proformas.ACEPTADA', 0);
    }

    public function test_detalle_y_pdf_de_otro_cliente_responden_404(): void
    {
        $solicitudB = SolicitudCotizacion::factory()->create(['cliente_id' => $this->clienteB->id]);
        $proformaB = $this->proforma($this->clienteB, EstadoProforma::Enviada);

        $this->actingAs($this->usuarioA)->getJson("/api/v1/portal/solicitudes/{$solicitudB->id}")
            ->assertNotFound()
            ->assertJsonPath('message', 'No encontramos ese registro.');
        $this->actingAs($this->usuarioA)->getJson("/api/v1/portal/proformas/{$proformaB->id}")
            ->assertNotFound();
        $this->actingAs($this->usuarioA)->get("/api/v1/portal/proformas/{$proformaB->id}/pdf")
            ->assertNotFound();

        // El dueño sí los ve.
        $this->actingAs($this->usuarioB)->getJson("/api/v1/portal/proformas/{$proformaB->id}")->assertOk();
        $this->actingAs($this->usuarioB)->get("/api/v1/portal/proformas/{$proformaB->id}/pdf")
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_cliente_id_de_la_peticion_se_ignora(): void
    {
        $this->actingAs($this->usuarioA)->postJson('/api/v1/portal/solicitudes', [
            'cliente_id' => $this->clienteB->id,
            'descripcion' => 'Intento a nombre de otro',
            'items' => [['descripcion' => 'Algo', 'cantidad' => 1]],
        ])->assertCreated();

        $this->assertSame(0, SolicitudCotizacion::where('cliente_id', $this->clienteB->id)->count());
        $this->assertSame(1, SolicitudCotizacion::where('cliente_id', $this->clienteA->id)->count());

        $this->actingAs($this->usuarioA)
            ->getJson("/api/v1/portal/proformas?cliente_id={$this->clienteB->id}")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_no_ve_datos_de_otra_empresa(): void
    {
        $empresa2 = Empresa::factory()->create();
        $clienteOtra = Cliente::factory()->create(['empresa_id' => $empresa2->id]);
        $proformaOtra = Proforma::factory()->enviada()->create([
            'cliente_id' => $clienteOtra->id,
            'empresa_id' => $empresa2->id,
        ]);
        Servicio::factory()->create(['empresa_id' => $empresa2->id, 'nombre' => 'Servicio de otra empresa']);

        $this->actingAs($this->usuarioA)->getJson("/api/v1/portal/proformas/{$proformaOtra->id}")->assertNotFound();
        $this->actingAs($this->usuarioA)->getJson('/api/v1/portal/servicios')
            ->assertOk()
            ->assertDontSee('Servicio de otra empresa');
    }

    // ---------------------------------------------------------------
    // Proformas
    // ---------------------------------------------------------------

    public function test_los_borradores_nunca_se_ven_ni_los_propios(): void
    {
        $borrador = $this->proforma($this->clienteA, EstadoProforma::Borrador);
        foreach ([EstadoProforma::Enviada, EstadoProforma::Aceptada, EstadoProforma::Rechazada] as $estado) {
            $this->proforma($this->clienteA, $estado);
        }

        $respuesta = $this->actingAs($this->usuarioA)->getJson('/api/v1/portal/proformas')->assertOk();
        $this->assertEqualsCanonicalizing(['ENVIADA', 'ACEPTADA', 'RECHAZADA'], array_column($respuesta->json('data'), 'estado'));

        $this->actingAs($this->usuarioA)->getJson("/api/v1/portal/proformas/{$borrador->id}")->assertNotFound();
        $this->actingAs($this->usuarioA)->get("/api/v1/portal/proformas/{$borrador->id}/pdf")->assertNotFound();
        $this->actingAs($this->usuarioA)->getJson('/api/v1/portal/proformas?estado=BORRADOR')->assertUnprocessable();
        $this->actingAs($this->usuarioA)->getJson('/api/v1/portal/resumen')
            ->assertJsonMissingPath('proformas.BORRADOR');
    }

    public function test_filtra_proformas_por_estado(): void
    {
        $this->proforma($this->clienteA, EstadoProforma::Enviada);
        $aceptada = $this->proforma($this->clienteA, EstadoProforma::Aceptada);

        $this->actingAs($this->usuarioA)->getJson('/api/v1/portal/proformas?estado=ACEPTADA')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $aceptada->id);
    }

    public function test_listado_de_proformas_trae_resumen_de_items_y_vencimiento(): void
    {
        $vencida = $this->proforma($this->clienteA, EstadoProforma::Enviada, [
            'fecha_emision' => now()->subDays(40)->toDateString(),
            'fecha_validez' => now()->subDays(10)->toDateString(),
        ]);

        $this->actingAs($this->usuarioA)->getJson('/api/v1/portal/proformas')
            ->assertOk()
            ->assertJsonPath('data.0.id', $vencida->id)
            ->assertJsonPath('data.0.primer_item', 'Instalación eléctrica')
            ->assertJsonPath('data.0.items_count', 2)
            ->assertJsonPath('data.0.vencida', true)
            ->assertJsonPath('data.0.total', 150);
    }

    public function test_detalle_muestra_observaciones_y_no_datos_internos(): void
    {
        $admin = User::factory()->admin()->create();
        $proforma = $this->proforma($this->clienteA, EstadoProforma::Rechazada, [
            'observaciones' => 'Incluye materiales.',
            'motivo_rechazo' => 'Precio alto según nota interna',
            'rechazada_at' => now(),
            'created_by' => $admin->id,
        ]);

        $respuesta = $this->actingAs($this->usuarioA)->getJson("/api/v1/portal/proformas/{$proforma->id}")
            ->assertOk()
            ->assertJsonPath('observaciones', 'Incluye materiales.')
            ->assertJsonCount(2, 'items')
            ->assertJsonPath('items.0.nombre', 'Instalación eléctrica')
            ->assertJsonMissingPath('motivo_rechazo')
            ->assertJsonMissingPath('created_by')
            ->assertJsonMissingPath('aceptada_por')
            ->assertJsonMissingPath('origen')
            ->assertJsonMissingPath('cliente');

        $this->assertStringNotContainsString('nota interna', $respuesta->getContent());
    }

    public function test_el_portal_no_acepta_ni_rechaza(): void
    {
        $proforma = $this->proforma($this->clienteA, EstadoProforma::Enviada);

        // En el portal no existe la ruta.
        $this->actingAs($this->usuarioA)->postJson("/api/v1/portal/proformas/{$proforma->id}/aceptar")
            ->assertNotFound();
        $this->actingAs($this->usuarioA)->postJson("/api/v1/proformas/{$proforma->id}/aceptar")->assertForbidden();
        $this->actingAs($this->usuarioA)->postJson("/api/v1/proformas/{$proforma->id}/rechazar", ['motivo' => 'x'])
            ->assertForbidden();

        $this->assertSame(EstadoProforma::Enviada, $proforma->fresh()->estado);
    }

    // ---------------------------------------------------------------
    // Solicitudes
    // ---------------------------------------------------------------

    public function test_crea_solicitud_pendiente_con_sus_items(): void
    {
        $respuesta = $this->actingAs($this->usuarioA)->postJson('/api/v1/portal/solicitudes', [
            'descripcion' => 'Necesito mantenimiento',
            'items' => [
                ['descripcion' => 'Mantenimiento preventivo', 'cantidad' => 2, 'unidad_medida' => 'Global', 'observaciones' => 'Por la mañana'],
                ['descripcion' => 'Cambio de foco', 'cantidad' => 3.5],
            ],
        ])->assertCreated()
            ->assertJsonPath('estado', 'PENDIENTE')
            ->assertJsonPath('items_count', 2)
            ->assertJsonPath('items.0.unidad_medida', 'Global')
            ->assertJsonPath('proforma', null);

        $solicitud = SolicitudCotizacion::findOrFail($respuesta->json('id'));

        $this->assertSame($this->clienteA->id, $solicitud->cliente_id);
        $this->assertSame(EstadoSolicitud::Pendiente, $solicitud->estado);
        $this->assertSame($this->usuarioA->id, $solicitud->created_by);
        $this->assertNotNull($respuesta->json('fecha'));

        // Aparece en el sistema interno.
        $this->actingAs(User::factory()->operario()->create())
            ->getJson('/api/v1/solicitudes')
            ->assertOk()
            ->assertJsonPath('data.0.id', $solicitud->id)
            ->assertJsonPath('data.0.cliente.id', $this->clienteA->id);
    }

    public function test_valida_la_solicitud(): void
    {
        $this->actingAs($this->usuarioA)->postJson('/api/v1/portal/solicitudes', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['descripcion', 'items']);

        $this->actingAs($this->usuarioA)->postJson('/api/v1/portal/solicitudes', [
            'descripcion' => 'X',
            'items' => [['descripcion' => '', 'cantidad' => 0]],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.descripcion', 'items.0.cantidad']);

        $this->assertSame(0, SolicitudCotizacion::count());
    }

    public function test_detalle_de_solicitud_trae_sus_items(): void
    {
        $solicitud = SolicitudCotizacion::factory()->create(['cliente_id' => $this->clienteA->id]);
        SolicitudItem::factory()->count(2)->create(['solicitud_id' => $solicitud->id]);

        $this->actingAs($this->usuarioA)->getJson("/api/v1/portal/solicitudes/{$solicitud->id}")
            ->assertOk()
            ->assertJsonCount(2, 'items')
            ->assertJsonPath('items_count', 2)
            ->assertJsonMissingPath('created_by');
    }

    public function test_la_proforma_de_una_solicitud_solo_se_enlaza_cuando_fue_enviada(): void
    {
        $borrador = $this->proforma($this->clienteA, EstadoProforma::Borrador);
        $enviada = $this->proforma($this->clienteA, EstadoProforma::Enviada);
        $conBorrador = SolicitudCotizacion::factory()->atendida()->create([
            'cliente_id' => $this->clienteA->id,
            'convertida_proforma_id' => $borrador->id,
        ]);
        $conEnviada = SolicitudCotizacion::factory()->atendida()->create([
            'cliente_id' => $this->clienteA->id,
            'convertida_proforma_id' => $enviada->id,
        ]);

        $this->actingAs($this->usuarioA)->getJson("/api/v1/portal/solicitudes/{$conBorrador->id}")
            ->assertOk()
            ->assertJsonPath('estado', 'ATENDIDA')
            ->assertJsonPath('proforma', null);

        $this->actingAs($this->usuarioA)->getJson("/api/v1/portal/solicitudes/{$conEnviada->id}")
            ->assertOk()
            ->assertJsonPath('proforma.id', $enviada->id)
            ->assertJsonPath('proforma.numero', $enviada->numero);
    }

    public function test_filtra_solicitudes_por_estado_y_muestra_rechazada_tal_cual(): void
    {
        SolicitudCotizacion::factory()->create(['cliente_id' => $this->clienteA->id]);
        $atendida = SolicitudCotizacion::factory()->atendida()->create(['cliente_id' => $this->clienteA->id]);
        SolicitudCotizacion::factory()->rechazada()->create(['cliente_id' => $this->clienteA->id]);

        $this->actingAs($this->usuarioA)->getJson('/api/v1/portal/solicitudes?estado=ATENDIDA')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $atendida->id);

        $estados = array_column(
            $this->actingAs($this->usuarioA)->getJson('/api/v1/portal/solicitudes')->json('data'),
            'estado',
        );
        $this->assertEqualsCanonicalizing(['PENDIENTE', 'ATENDIDA', 'RECHAZADA'], $estados);
    }

    public function test_limita_el_envio_de_solicitudes(): void
    {
        $datos = ['descripcion' => 'X', 'items' => [['descripcion' => 'Y', 'cantidad' => 1]]];

        for ($i = 0; $i < 10; $i++) {
            $this->actingAs($this->usuarioA)->postJson('/api/v1/portal/solicitudes', $datos)->assertCreated();
        }

        $this->actingAs($this->usuarioA)->postJson('/api/v1/portal/solicitudes', $datos)->assertStatus(429);
    }

    // ---------------------------------------------------------------
    // Catálogo, empresa e inicio
    // ---------------------------------------------------------------

    public function test_catalogo_solo_trae_servicios_activos_y_visibles(): void
    {
        $visible = Servicio::factory()->create([
            'nombre' => 'Pintura de interiores',
            'categoria' => 'Pintura',
            'precio_base' => 250,
            'duracion_estimada_min' => 120,
            'activo' => true,
            'visible_catalogo' => true,
        ]);
        $inactivo = Servicio::factory()->inactivo()->create(['nombre' => 'Servicio inactivo']);
        $oculto = Servicio::factory()->oculto()->create(['nombre' => 'Servicio oculto']);

        $this->actingAs($this->usuarioA)->getJson('/api/v1/portal/servicios')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.nombre', 'Pintura de interiores')
            ->assertJsonPath('data.0.categoria', 'Pintura')
            ->assertJsonPath('data.0.precio_base', 250)
            ->assertJsonPath('data.0.duracion_estimada_min', 120)
            ->assertJsonMissingPath('data.0.activo')
            ->assertJsonMissingPath('data.0.empresa_id');

        $this->actingAs($this->usuarioA)->getJson("/api/v1/portal/servicios/{$visible->id}")->assertOk();
        $this->actingAs($this->usuarioA)->getJson("/api/v1/portal/servicios/{$inactivo->id}")->assertNotFound();
        $this->actingAs($this->usuarioA)->getJson("/api/v1/portal/servicios/{$oculto->id}")->assertNotFound();
    }

    public function test_catalogo_busca_por_nombre_o_categoria(): void
    {
        Servicio::factory()->create(['nombre' => 'Pintura de interiores', 'categoria' => 'Acabados', 'visible_catalogo' => true, 'activo' => true]);
        Servicio::factory()->create(['nombre' => 'Instalación', 'categoria' => 'Electricidad', 'visible_catalogo' => true, 'activo' => true]);

        $this->actingAs($this->usuarioA)->getJson('/api/v1/portal/servicios?search=pintura')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.nombre', 'Pintura de interiores');
        $this->actingAs($this->usuarioA)->getJson('/api/v1/portal/servicios?search=electricidad')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.nombre', 'Instalación');
    }

    public function test_empresa_devuelve_sus_datos_de_contacto(): void
    {
        $empresa = Empresa::query()->findOrFail($this->usuarioA->empresa_id);
        $empresa->update(['telefono' => '44412345', 'email' => 'contacto@empresa.test']);

        $this->actingAs($this->usuarioA)->getJson('/api/v1/portal/empresa')
            ->assertOk()
            ->assertJsonPath('nombre', $empresa->nombre)
            ->assertJsonPath('telefono', '44412345')
            ->assertJsonPath('email', 'contacto@empresa.test')
            ->assertJsonMissingPath('nit')
            ->assertJsonMissingPath('condiciones');
    }

    public function test_resumen_trae_lo_ultimo_que_se_movio(): void
    {
        $this->travelTo(now()->subDays(3));
        SolicitudCotizacion::factory()->create(['cliente_id' => $this->clienteA->id, 'descripcion' => 'Solicitud vieja']);
        $this->travelBack();

        $proforma = $this->proforma($this->clienteA, EstadoProforma::Aceptada, [
            'enviada_at' => now()->subDay(),
            'aceptada_at' => now(),
        ]);
        // Un borrador más nuevo no cuenta: el cliente no lo ve.
        $this->proforma($this->clienteA, EstadoProforma::Borrador);

        $this->actingAs($this->usuarioA)->getJson('/api/v1/portal/resumen')
            ->assertOk()
            ->assertJsonPath('ultimo.tipo', 'proforma')
            ->assertJsonPath('ultimo.id', $proforma->id)
            ->assertJsonPath('ultimo.estado', 'ACEPTADA')
            ->assertJsonPath('solicitudes.PENDIENTE', 1)
            ->assertJsonPath('solicitudes.ATENDIDA', 0)
            ->assertJsonPath('proformas.ACEPTADA', 1);

        $this->actingAs($this->usuarioB)->getJson('/api/v1/portal/resumen')
            ->assertOk()
            ->assertJsonPath('ultimo', null)
            ->assertJsonPath('proformas.ACEPTADA', 0);
    }
}
