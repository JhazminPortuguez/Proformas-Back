<?php

namespace Tests\Feature;

use App\Enums\Rol;
use App\Enums\TipoPromocion;
use App\Http\Controllers\Api\PromocionController;
use App\Mail\ProformaEnviadaMail;
use App\Models\Cliente;
use App\Models\CuentaPorCobrar;
use App\Models\Empresa;
use App\Models\Producto;
use App\Models\Proforma;
use App\Models\Promocion;
use App\Models\Servicio;
use App\Models\User;
use App\Support\DescuentoPromocion;
use App\Support\FechaBolivia;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Ronda C, parte 1: promociones (catálogo, líneas de la proforma, orden,
 * cuenta por cobrar, reporte) y sus casos borde PR-01 a PR-12.
 */
class PromocionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->admin = User::factory()->create(['rol' => Rol::Admin]);
    }

    /* ------------------------------------------------------------------ */
    /* Catálogo */
    /* ------------------------------------------------------------------ */

    public function test_el_admin_crea_lista_edita_y_desactiva_promociones(): void
    {
        $servicio = Servicio::factory()->create();
        $hoy = FechaBolivia::hoyTexto();

        $id = $this->actingAs($this->admin)->postJson('/api/v1/promociones', [
            'nombre' => 'Mantenimiento 10 %',
            'tipo' => 'PORCENTAJE',
            'valor' => 10,
            'aplica_a' => 'SERVICIO',
            'servicio_id' => $servicio->id,
            'vigente_desde' => $hoy,
            'vigente_hasta' => $hoy,
        ])->assertCreated()
            ->assertJsonPath('etiqueta', '10 %')
            ->assertJsonPath('estado', 'VIGENTE')
            ->assertJsonPath('servicio.nombre', $servicio->nombre)
            ->json('id');

        $this->actingAs($this->admin)->getJson('/api/v1/promociones?vigentes=1')
            ->assertOk()
            ->assertJsonPath('data.0.id', $id);

        $this->actingAs($this->admin)->putJson("/api/v1/promociones/{$id}", [
            'nombre' => 'Mantenimiento 10 %',
            'tipo' => 'PORCENTAJE',
            'valor' => 10,
            'aplica_a' => 'SERVICIO',
            'servicio_id' => $servicio->id,
            'vigente_desde' => $hoy,
            'vigente_hasta' => $hoy,
            'activa' => false,
        ])->assertOk()->assertJsonPath('estado', 'INACTIVA');

        $this->actingAs($this->admin)->getJson('/api/v1/promociones?vigentes=1')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_solo_el_admin_gestiona_y_el_operario_solo_las_ve(): void
    {
        $operario = User::factory()->create(['rol' => Rol::Operario]);
        $promocion = Promocion::factory()->create();

        $this->actingAs($operario)->getJson('/api/v1/promociones?vigentes=1')->assertOk();
        $this->actingAs($operario)->postJson('/api/v1/promociones', $this->datos())->assertForbidden();
        $this->actingAs($operario)->putJson("/api/v1/promociones/{$promocion->id}", $this->datos())->assertForbidden();
        $this->actingAs($operario)->deleteJson("/api/v1/promociones/{$promocion->id}")->assertForbidden();

        $superusuario = User::factory()->superusuario()->create();
        $this->actingAs($superusuario)->postJson('/api/v1/promociones', $this->datos())->assertForbidden();
    }

    /** PR-05: el porcentaje va de 1 a 100. */
    public function test_el_porcentaje_solo_puede_ir_de_1_a_100(): void
    {
        foreach ([0, 0.5, 100.01, 150, -5] as $valor) {
            $this->actingAs($this->admin)->postJson('/api/v1/promociones', $this->datos(['valor' => $valor]))
                ->assertStatus(422)
                ->assertJsonPath('errors.valor.0', 'El porcentaje debe estar entre 1 y 100.');
        }

        $this->actingAs($this->admin)->postJson('/api/v1/promociones', $this->datos(['valor' => null]))
            ->assertStatus(422)
            ->assertJsonPath('errors.valor.0', 'Escribe el porcentaje (de 1 a 100).');

        foreach ([1, 100] as $valor) {
            $this->actingAs($this->admin)->postJson('/api/v1/promociones', $this->datos(['valor' => $valor]))->assertCreated();
        }
    }

    /** PR-06: "hasta" no puede ser anterior a "desde". */
    public function test_la_fecha_hasta_no_puede_ser_anterior_a_desde(): void
    {
        $this->actingAs($this->admin)->postJson('/api/v1/promociones', $this->datos([
            'vigente_desde' => '2026-12-10',
            'vigente_hasta' => '2026-12-09',
        ]))->assertStatus(422)
            ->assertJsonPath('errors.vigente_hasta.0', 'La fecha "hasta" no puede ser anterior a la fecha "desde".');

        // El mismo día sí vale.
        $this->actingAs($this->admin)->postJson('/api/v1/promociones', $this->datos([
            'vigente_desde' => '2026-12-10',
            'vigente_hasta' => '2026-12-10',
        ]))->assertCreated()->assertJsonPath('estado', 'PROGRAMADA');
    }

    public function test_monto_fijo_mayor_a_cero_2x1_sin_valor_y_referencia_segun_alcance(): void
    {
        $this->actingAs($this->admin)->postJson('/api/v1/promociones', $this->datos(['tipo' => 'MONTO_FIJO', 'valor' => 0]))
            ->assertStatus(422)
            ->assertJsonPath('errors.valor.0', 'El monto debe ser mayor a Bs 0.');

        $producto = Producto::factory()->create();

        $this->actingAs($this->admin)->postJson('/api/v1/promociones', $this->datos([
            'tipo' => 'DOS_POR_UNO',
            'valor' => 30,
            'aplica_a' => 'PRODUCTO',
            'producto_id' => $producto->id,
            // Se ignora: el alcance es un producto.
            'servicio_id' => Servicio::factory()->create()->id,
        ]))->assertCreated()
            ->assertJsonPath('valor', null)
            ->assertJsonPath('etiqueta', '2x1')
            ->assertJsonPath('servicio_id', null)
            ->assertJsonPath('producto_id', $producto->id);

        $this->actingAs($this->admin)->postJson('/api/v1/promociones', $this->datos(['aplica_a' => 'SERVICIO']))
            ->assertStatus(422)
            ->assertJsonPath('errors.servicio_id.0', 'Elige el servicio de la promoción.');
    }

    /** PR-01: una promoción usada no se borra, solo se desactiva. */
    public function test_una_promocion_usada_no_se_borra_solo_se_desactiva(): void
    {
        $libre = Promocion::factory()->create();
        $this->actingAs($this->admin)->deleteJson("/api/v1/promociones/{$libre->id}")->assertNoContent();

        $usada = Promocion::factory()->create();
        $this->crearProforma([$this->lineaLibre(100, 1, $usada->id)])->assertCreated();

        $this->actingAs($this->admin)->deleteJson("/api/v1/promociones/{$usada->id}")
            ->assertStatus(409)
            ->assertJsonPath('message', PromocionController::MENSAJE_USADA);

        $this->assertDatabaseHas('promociones', ['id' => $usada->id]);
    }

    /* ------------------------------------------------------------------ */
    /* Líneas de la proforma */
    /* ------------------------------------------------------------------ */

    public function test_la_linea_copia_la_promocion_y_muestra_precio_descuento_y_total(): void
    {
        $servicio = Servicio::factory()->create(['precio_base' => 200]);
        $promocion = Promocion::factory()->porcentaje(15)->paraServicio($servicio->id)->create(['nombre' => 'Quince']);

        $proforma = $this->crearProforma([
            ['tipo' => 'SERVICIO', 'servicio_id' => $servicio->id, 'cantidad' => 2, 'promocion_id' => $promocion->id],
        ], ['impuesto_tasa' => 0])->assertCreated();

        $proforma->assertJsonPath('items.0.importe_bruto', 400)
            ->assertJsonPath('items.0.descuento_promocion', 60)
            ->assertJsonPath('items.0.total_linea', 340)
            ->assertJsonPath('items.0.promocion.nombre', 'Quince')
            ->assertJsonPath('items.0.promocion.etiqueta', '15 %')
            ->assertJsonPath('subtotal', 340)
            ->assertJsonPath('descuento_promocion', 60)
            ->assertJsonPath('total', 340);

        $this->assertDatabaseHas('proforma_items', [
            'promocion_id' => $promocion->id,
            'promocion_nombre' => 'Quince',
            'promocion_tipo' => 'PORCENTAJE',
        ]);
    }

    /** PR-02: una promoción vencida o inactiva no se puede aplicar. */
    public function test_una_promocion_vencida_o_inactiva_no_se_aplica(): void
    {
        $vencida = Promocion::factory()->vencida()->create(['nombre' => 'Vieja']);
        $inactiva = Promocion::factory()->inactiva()->create(['nombre' => 'Apagada']);
        $futura = Promocion::factory()->create([
            'nombre' => 'Futura',
            'vigente_desde' => FechaBolivia::hoy()->addDays(3)->toDateString(),
            'vigente_hasta' => FechaBolivia::hoy()->addDays(9)->toDateString(),
        ]);

        $this->crearProforma([$this->lineaLibre(100, 1, $vencida->id)])
            ->assertStatus(422)
            ->assertJsonPath('errors', fn (array $errores) => str_contains($errores['items.0.promocion_id'][0], 'venció'));

        $this->crearProforma([$this->lineaLibre(100, 1, $inactiva->id)])
            ->assertStatus(422)
            ->assertJsonPath('errors', fn (array $errores) => str_contains($errores['items.0.promocion_id'][0], 'inactiva'));

        $this->crearProforma([$this->lineaLibre(100, 1, $futura->id)])
            ->assertStatus(422)
            ->assertJsonPath('errors', fn (array $errores) => str_contains($errores['items.0.promocion_id'][0], 'empieza'));

        $this->assertSame(0, Proforma::query()->count());
    }

    public function test_la_promocion_debe_corresponder_al_item(): void
    {
        $servicio = Servicio::factory()->create(['precio_base' => 100]);
        $otro = Servicio::factory()->create(['precio_base' => 100]);
        $promocion = Promocion::factory()->paraServicio($servicio->id)->create(['nombre' => 'Solo uno']);

        $this->crearProforma([
            ['tipo' => 'SERVICIO', 'servicio_id' => $otro->id, 'cantidad' => 1, 'promocion_id' => $promocion->id],
        ])->assertStatus(422)
            ->assertJsonPath('errors', fn (array $errores) => $errores['items.0.promocion_id'][0] === 'La promoción "Solo uno" no se aplica a este ítem.');

        // Una de "cualquier ítem" sirve también para una línea libre.
        $cualquiera = Promocion::factory()->create();
        $this->crearProforma([$this->lineaLibre(100, 1, $cualquiera->id)])->assertCreated();
    }

    /** PR-04: el 2x1 con cantidad 1 no descuenta y con 3 cobra 2. */
    public function test_dos_por_uno_con_cantidad_1_no_descuenta_y_con_3_cobra_2(): void
    {
        $promocion = Promocion::factory()->dosPorUno()->create();

        $this->crearProforma([$this->lineaLibre(50, 1, $promocion->id)], ['impuesto_tasa' => 0])
            ->assertCreated()
            ->assertJsonPath('items.0.descuento_promocion', 0)
            ->assertJsonPath('items.0.total_linea', 50);

        $this->crearProforma([$this->lineaLibre(50, 3, $promocion->id)], ['impuesto_tasa' => 0])
            ->assertCreated()
            ->assertJsonPath('items.0.descuento_promocion', 50)
            ->assertJsonPath('items.0.total_linea', 100);

        $this->crearProforma([$this->lineaLibre(50, 4, $promocion->id)], ['impuesto_tasa' => 0])
            ->assertCreated()
            ->assertJsonPath('items.0.total_linea', 100);
    }

    /** PR-07: un monto fijo mayor que la línea la deja en Bs 0, nunca en negativo. */
    public function test_monto_fijo_mayor_que_la_linea_la_deja_en_cero(): void
    {
        $promocion = Promocion::factory()->montoFijo(500)->create();

        $this->crearProforma([
            $this->lineaLibre(120, 1, $promocion->id),
            $this->lineaLibre(80, 1),
        ], ['impuesto_tasa' => 0])->assertCreated()
            ->assertJsonPath('items.0.descuento_promocion', 120)
            ->assertJsonPath('items.0.total_linea', 0)
            ->assertJsonPath('subtotal', 80)
            ->assertJsonPath('total', 80);

        $this->assertSame('0.00', DescuentoPromocion::calcular(TipoPromocion::MontoFijo, 500, 1, 0));
        $this->assertSame('30.00', DescuentoPromocion::calcular(TipoPromocion::MontoFijo, 30, 2, 100));
    }

    /** PR-08: una línea de cortesía (Bs 0) no acepta promoción. */
    public function test_una_linea_de_cortesia_no_acepta_promocion(): void
    {
        $promocion = Promocion::factory()->create();

        $this->crearProforma([$this->lineaLibre(0, 1, $promocion->id), $this->lineaLibre(10, 1)])
            ->assertStatus(422)
            ->assertJsonPath('errors', fn (array $errores) => $errores['items.0.promocion_id'][0] === 'Una línea de cortesía (Bs 0) no lleva promoción.');
    }

    /** PR-03: una proforma cuya promoción venció antes de enviarse no se envía. */
    public function test_proforma_con_promocion_vencida_antes_de_enviarse_no_se_envia(): void
    {
        $promocion = Promocion::factory()->create(['nombre' => 'Fin de mes']);
        $id = $this->crearProforma([$this->lineaLibre(100, 1, $promocion->id)])->assertCreated()->json('id');

        $promocion->update(['vigente_hasta' => FechaBolivia::hoy()->subDay()->toDateString(), 'vigente_desde' => FechaBolivia::hoy()->subDays(10)->toDateString()]);

        $this->actingAs($this->admin)->postJson("/api/v1/proformas/{$id}/enviar")
            ->assertStatus(422)
            ->assertJsonPath('errors.promocion.0', fn (string $mensaje) => str_contains($mensaje, 'venció') && str_contains($mensaje, 'quita la promoción'));

        $this->assertSame('BORRADOR', Proforma::query()->find($id)->estado->value);

        // Desactivada tampoco.
        $promocion->update(['vigente_hasta' => FechaBolivia::hoy()->addDay()->toDateString(), 'activa' => false]);
        $this->actingAs($this->admin)->postJson("/api/v1/proformas/{$id}/enviar")->assertStatus(422);

        $promocion->update(['activa' => true]);
        $this->actingAs($this->admin)->postJson("/api/v1/proformas/{$id}/enviar")->assertOk();
    }

    /** PR-09: editar una promoción no cambia las proformas ya guardadas. */
    public function test_editar_una_promocion_no_cambia_las_proformas_guardadas(): void
    {
        $promocion = Promocion::factory()->porcentaje(10)->create(['nombre' => 'Diez']);
        $id = $this->crearProforma([$this->lineaLibre(100, 1, $promocion->id)], ['impuesto_tasa' => 0])
            ->assertCreated()
            ->json('id');

        $this->actingAs($this->admin)->putJson("/api/v1/promociones/{$promocion->id}", [
            ...$this->datos(),
            'nombre' => 'Veinte',
            'valor' => 20,
        ])->assertOk();

        $this->actingAs($this->admin)->getJson("/api/v1/proformas/{$id}")
            ->assertJsonPath('items.0.promocion.nombre', 'Diez')
            ->assertJsonPath('items.0.descuento_promocion', 10)
            ->assertJsonPath('total', 90);

        // Al volver a guardar el borrador con la misma promoción, la línea
        // conserva la copia con que se guardó (y se recalcula con la cantidad nueva).
        $this->actingAs($this->admin)->putJson("/api/v1/proformas/{$id}", [
            'items' => [$this->lineaLibre(100, 2, $promocion->id)],
        ])->assertOk()
            ->assertJsonPath('items.0.promocion.nombre', 'Diez')
            ->assertJsonPath('items.0.descuento_promocion', 20)
            ->assertJsonPath('total', 180);

        // Una proforma nueva usa los valores de ahora.
        $this->crearProforma([$this->lineaLibre(100, 1, $promocion->id)], ['impuesto_tasa' => 0])
            ->assertJsonPath('items.0.promocion.nombre', 'Veinte')
            ->assertJsonPath('total', 80);
    }

    public function test_quitar_la_promocion_al_editar_el_borrador_restaura_el_precio(): void
    {
        $promocion = Promocion::factory()->porcentaje(50)->create();
        $id = $this->crearProforma([$this->lineaLibre(100, 1, $promocion->id)], ['impuesto_tasa' => 0])->json('id');

        $this->actingAs($this->admin)->putJson("/api/v1/proformas/{$id}", ['items' => [$this->lineaLibre(100, 1)]])
            ->assertOk()
            ->assertJsonPath('items.0.promocion', null)
            ->assertJsonPath('descuento_promocion', 0)
            ->assertJsonPath('total', 100);
    }

    /* ------------------------------------------------------------------ */
    /* La promoción viaja con la venta */
    /* ------------------------------------------------------------------ */

    public function test_la_orden_y_la_cuenta_por_cobrar_copian_el_descuento(): void
    {
        $operario = User::factory()->create(['rol' => Rol::Operario]);
        $servicio = Servicio::factory()->create(['precio_base' => 300]);
        $promocion = Promocion::factory()->montoFijo(50)->paraServicio($servicio->id)->create(['nombre' => 'Cincuenta']);

        $id = $this->crearProforma([
            ['tipo' => 'SERVICIO', 'servicio_id' => $servicio->id, 'cantidad' => 1, 'promocion_id' => $promocion->id],
            $this->lineaLibre(100, 1),
        ], ['impuesto_tasa' => 0])->assertCreated()->json('id');

        $this->actingAs($this->admin)->postJson("/api/v1/proformas/{$id}/enviar")->assertOk();
        $this->actingAs($this->admin)->postJson("/api/v1/proformas/{$id}/aceptar")->assertOk();

        $orden = $this->actingAs($this->admin)->postJson('/api/v1/ordenes', [
            'proforma_id' => $id,
            'operario_id' => $operario->id,
        ])->assertCreated();

        $orden->assertJsonPath('items.0.promocion.nombre', 'Cincuenta')
            ->assertJsonPath('items.0.descuento_promocion', 50)
            ->assertJsonPath('items.0.total_linea', 250);

        $ordenId = $orden->json('id');

        // Aunque la promoción cambie, la venta ya tiene su copia.
        $promocion->update(['valor' => 100, 'nombre' => 'Cien']);

        $this->actingAs($this->admin)->postJson("/api/v1/ordenes/{$ordenId}/iniciar")->assertOk();
        $this->actingAs($this->admin)->postJson("/api/v1/ordenes/{$ordenId}/finalizar")->assertOk();
        $this->actingAs($this->admin)->postJson("/api/v1/ordenes/{$ordenId}/cerrar")->assertOk();

        $cuenta = CuentaPorCobrar::query()->where('orden_id', $ordenId)->firstOrFail();

        $this->assertSame('350.00', (string) $cuenta->monto_total);
        $this->assertSame('50.00', (string) $cuenta->descuento_promocion);

        $this->actingAs($this->admin)->getJson("/api/v1/cuentas-por-cobrar/{$cuenta->id}")
            ->assertOk()
            ->assertJsonPath('descuento_promocion', 50)
            ->assertJsonPath('monto_total', 350);
    }

    public function test_al_cancelar_no_se_cobra_el_material_por_encima_del_total_con_promocion(): void
    {
        $operario = User::factory()->create(['rol' => Rol::Operario]);
        $producto = Producto::factory()->create(['precio_unitario' => 40, 'stock_actual' => 10]);
        $promocion = Promocion::factory()->dosPorUno()->paraProducto($producto->id)->create();

        $id = $this->crearProforma([
            ['tipo' => 'MATERIAL', 'producto_id' => $producto->id, 'cantidad' => 2, 'promocion_id' => $promocion->id],
        ], ['impuesto_tasa' => 0])->assertJsonPath('total', 40)->json('id');

        $this->actingAs($this->admin)->postJson("/api/v1/proformas/{$id}/enviar")->assertOk();
        $this->actingAs($this->admin)->postJson("/api/v1/proformas/{$id}/aceptar")->assertOk();
        $ordenId = $this->actingAs($this->admin)->postJson('/api/v1/ordenes', ['proforma_id' => $id, 'operario_id' => $operario->id])->json('id');
        $this->actingAs($this->admin)->postJson("/api/v1/ordenes/{$ordenId}/iniciar")->assertOk();
        $this->actingAs($this->admin)->postJson("/api/v1/ordenes/{$ordenId}/consumir")->assertOk();

        $this->actingAs($this->admin)->postJson("/api/v1/ordenes/{$ordenId}/cancelar", [
            'motivo' => 'El cliente desistió.',
            'cobrar_materiales' => true,
        ])->assertOk();

        // 2 unidades a Bs 40 serían Bs 80; con el 2x1 el cliente aceptó Bs 40.
        $this->assertSame('40.00', (string) CuentaPorCobrar::query()->where('orden_id', $ordenId)->value('monto_total'));
    }

    public function test_el_portal_el_pdf_y_el_correo_muestran_la_promocion(): void
    {
        $cliente = Cliente::factory()->create(['email' => 'cliente@example.com']);
        $usuarioCliente = User::factory()->cliente()->create(['cliente_id' => $cliente->id]);
        $promocion = Promocion::factory()->porcentaje(10)->create(['nombre' => 'Aniversario']);

        $id = $this->crearProforma([$this->lineaLibre(100, 1, $promocion->id)], ['cliente_id' => $cliente->id, 'impuesto_tasa' => 0])
            ->assertCreated()
            ->json('id');

        $this->actingAs($this->admin)->postJson("/api/v1/proformas/{$id}/enviar")->assertOk();

        $this->actingAs($usuarioCliente)->getJson("/api/v1/portal/proformas/{$id}")
            ->assertOk()
            ->assertJsonPath('descuento_promocion', 10)
            ->assertJsonPath('items.0.promocion.nombre', 'Aniversario')
            ->assertJsonPath('items.0.importe_bruto', 100);

        $proforma = Proforma::query()->with('items', 'cliente')->findOrFail($id);
        $html = view('proformas.pdf', [
            'proforma' => $proforma,
            'empresa' => Empresa::query()->firstOrFail(),
            'cliente' => $proforma->cliente,
            'items' => $proforma->items,
            'simbolo' => 'Bs',
            'moneda' => 'BOB',
        ])->render();

        $this->assertStringContainsString('Promoción: Aniversario (10 %)', $html);
        $this->assertStringContainsString('Incluye descuento por promociones de Bs 10,00', $html);

        $correo = (new ProformaEnviadaMail($proforma, Empresa::query()->firstOrFail(), 'pdf'))->render();
        $this->assertStringContainsString('Descuento por promociones', $correo);
        $this->assertStringContainsString('Aniversario (10 %)', $correo);
    }

    /* ------------------------------------------------------------------ */
    /* Reporte */
    /* ------------------------------------------------------------------ */

    public function test_reporte_cuenta_usos_y_descuento_sin_borradores_y_exporta(): void
    {
        $diez = Promocion::factory()->porcentaje(10)->create(['nombre' => 'Diez']);
        $dos = Promocion::factory()->dosPorUno()->create(['nombre' => 'Dos por uno']);

        $enviada = $this->crearProforma([
            $this->lineaLibre(100, 1, $diez->id),
            $this->lineaLibre(40, 2, $dos->id),
        ], ['impuesto_tasa' => 0])->json('id');
        $this->actingAs($this->admin)->postJson("/api/v1/proformas/{$enviada}/enviar")->assertOk();

        $aceptada = $this->crearProforma([$this->lineaLibre(200, 1, $diez->id)], ['impuesto_tasa' => 0])->json('id');
        $this->actingAs($this->admin)->postJson("/api/v1/proformas/{$aceptada}/enviar")->assertOk();
        $this->actingAs($this->admin)->postJson("/api/v1/proformas/{$aceptada}/aceptar")->assertOk();

        // Un borrador no cuenta.
        $this->crearProforma([$this->lineaLibre(500, 1, $diez->id)])->assertCreated();

        $hoy = FechaBolivia::hoyTexto();
        $consulta = "desde={$hoy}&hasta={$hoy}";

        $this->actingAs($this->admin)->getJson("/api/v1/reportes/promociones?{$consulta}")
            ->assertOk()
            ->assertJsonPath('usos', 3)
            ->assertJsonPath('proformas', 2)
            ->assertJsonPath('descontado', 70)
            ->assertJsonPath('descontado_ventas', 20)
            ->assertJsonPath('promociones.0.nombre', 'Dos por uno')
            ->assertJsonPath('promociones.0.descontado', 40)
            ->assertJsonPath('promociones.1.nombre', 'Diez')
            ->assertJsonPath('promociones.1.usos', 2)
            ->assertJsonPath('promociones.1.descontado', 30);

        $csv = $this->actingAs($this->admin)->get("/api/v1/reportes/promociones?{$consulta}&formato=csv")->assertOk();
        $this->assertStringContainsString('Dos por uno', $csv->streamedContent());

        $this->actingAs($this->admin)->get("/api/v1/reportes/promociones?{$consulta}&formato=pdf")
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $this->actingAs($this->admin)->get("/api/v1/reportes/promociones?{$consulta}&formato=xlsx")->assertOk();

        $operario = User::factory()->create(['rol' => Rol::Operario]);
        $this->actingAs($operario)->getJson("/api/v1/reportes/promociones?{$consulta}")->assertForbidden();
    }

    public function test_postgres_rechaza_promociones_invalidas(): void
    {
        if ($this->app['db']->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Solo PostgreSQL tiene las restricciones CHECK.');
        }

        $this->expectException(QueryException::class);

        Promocion::factory()->create(['tipo' => 'PORCENTAJE', 'valor' => 150]);
    }

    /* ------------------------------------------------------------------ */

    /**
     * @param  array<string, mixed>  $cambios
     * @return array<string, mixed>
     */
    private function datos(array $cambios = []): array
    {
        $hoy = FechaBolivia::hoyTexto();

        return [
            'nombre' => 'Promoción de prueba',
            'tipo' => 'PORCENTAJE',
            'valor' => 10,
            'aplica_a' => 'CUALQUIERA',
            'vigente_desde' => $hoy,
            'vigente_hasta' => $hoy,
            ...$cambios,
        ];
    }

    /** @return array<string, mixed> */
    private function lineaLibre(float $precio, float $cantidad, ?int $promocionId = null): array
    {
        return [
            'tipo' => 'LIBRE',
            'descripcion' => 'Trabajo a medida',
            'cantidad' => $cantidad,
            'precio_unitario' => $precio,
            'promocion_id' => $promocionId,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @param  array<string, mixed>  $extra
     */
    private function crearProforma(array $items, array $extra = []): TestResponse
    {
        return $this->actingAs($this->admin)->postJson('/api/v1/proformas', [
            'cliente_id' => $extra['cliente_id'] ?? Cliente::factory()->create()->id,
            'fecha_emision' => FechaBolivia::hoyTexto(),
            'items' => $items,
            ...$extra,
        ]);
    }
}
