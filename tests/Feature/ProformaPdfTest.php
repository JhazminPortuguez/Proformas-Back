<?php

namespace Tests\Feature;

use App\Enums\Rol;
use App\Models\Cliente;
use App\Models\Empresa;
use App\Models\Producto;
use App\Models\Proforma;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProformaPdfTest extends TestCase
{
    use RefreshDatabase;

    public function test_operario_descarga_pdf_de_proforma(): void
    {
        Empresa::query()->whereKey(1)->update([
            'nombre' => 'Empresa de Prueba',
            'nit' => '123456789',
            'condiciones' => 'Pago a 30 días.',
        ]);

        $operario = User::factory()->create(['rol' => Rol::Operario]);
        $cliente = Cliente::factory()->create();
        $producto = Producto::factory()->create(['precio_unitario' => 100]);

        $proforma = $this->actingAs($operario)->postJson('/api/v1/proformas', [
            'cliente_id' => $cliente->id,
            'fecha_emision' => '2026-10-23',
            'impuesto_tasa' => 13,
            'items' => [['producto_id' => $producto->id, 'cantidad' => 2]],
        ])->assertCreated()->json();

        $response = $this->actingAs($operario)
            ->get("/api/v1/proformas/{$proforma['id']}/pdf");

        $response->assertOk()
            ->assertHeader('content-type', 'application/pdf')
            ->assertDownload("{$proforma['numero']}.pdf");

        $this->assertStringStartsWith('%PDF', (string) $response->getContent());
    }

    public function test_el_pdf_incrusta_solo_los_glifos_usados_y_pesa_poco(): void
    {
        $operario = User::factory()->create(['rol' => Rol::Operario]);
        $cliente = Cliente::factory()->create(['nombre_razon' => 'Compañía Ñandú y Peñaranda']);
        $producto = Producto::factory()->create(['nombre' => 'Cañería eléctrica', 'precio_unitario' => 1250.75]);

        $proforma = $this->actingAs($operario)->postJson('/api/v1/proformas', [
            'cliente_id' => $cliente->id,
            'fecha_emision' => '2026-10-23',
            'items' => [['producto_id' => $producto->id, 'cantidad' => 2]],
        ])->assertCreated()->json();

        $pdf = (string) $this->actingAs($operario)->get("/api/v1/proformas/{$proforma['id']}/pdf")->assertOk()->getContent();

        // Con la fuente DejaVu completa pesaba ~860 KB; con subconjunto, ~30 KB.
        $this->assertLessThan(150_000, strlen($pdf));
        // Las fuentes de un subconjunto llevan un prefijo de 6 letras: ABCDEF+DejaVuSans.
        $this->assertMatchesRegularExpression('#/BaseFont\s*/[A-Z]{6}\+DejaVuSans#', $pdf);
    }

    public function test_los_montos_usan_el_mismo_formato_que_la_pantalla(): void
    {
        // Antes: "Bs 1,250.75" (formato inglés); la pantalla y los reportes
        // dicen "Bs 1.250,75".
        $operario = User::factory()->create(['rol' => Rol::Operario]);
        $cliente = Cliente::factory()->create();

        $creada = $this->actingAs($operario)->postJson('/api/v1/proformas', [
            'cliente_id' => $cliente->id,
            'fecha_emision' => '2026-10-23',
            'impuesto_tasa' => 13,
            'descuento' => 25.5,
            'items' => [
                ['tipo' => 'LIBRE', 'descripcion' => 'Instalación', 'cantidad' => 2, 'precio_unitario' => 1250.75],
                ['tipo' => 'LIBRE', 'descripcion' => 'Cable', 'cantidad' => 12.5, 'precio_unitario' => 18.4],
            ],
        ])->assertCreated()->json();

        $proforma = Proforma::query()->with('cliente', 'items.producto', 'items.servicio', 'aceptador')->findOrFail($creada['id']);

        $html = view('proformas.pdf', [
            'proforma' => $proforma,
            'cliente' => $proforma->cliente,
            'items' => $proforma->items,
            'empresa' => new Empresa(['nombre' => 'Empresa']),
            'moneda' => 'BOB',
            'simbolo' => 'Bs',
        ])->render();

        foreach ([
            'Bs 1.250,75',   // precio unitario
            'Bs 2.501,50',   // total de la línea
            '12,5',          // cantidad con decimales
            'Bs 2.731,50',   // subtotal: 2.501,50 + 230,00
            '- Bs 25,50',    // descuento
            'Impuesto (13,00 %)',
            'Bs 351,78',     // impuesto: 13 % de 2.706,00
            'Bs 3.057,78 BOB',
        ] as $texto) {
            $this->assertStringContainsString($texto, $html);
        }

        $this->assertStringNotContainsString('1,250.75', $html);
        $this->assertStringNotContainsString('3,057.78', $html);
    }

    public function test_cliente_no_puede_descargar_pdf(): void
    {
        $cliente = User::factory()->create(['rol' => Rol::Cliente]);
        $proforma = Proforma::factory()->create();

        $this->actingAs($cliente)
            ->get("/api/v1/proformas/{$proforma->id}/pdf")
            ->assertForbidden();
    }

    public function test_los_datos_del_cliente_no_dejan_un_separador_suelto(): void
    {
        $empresa = Empresa::query()->findOrFail(1);
        $html = function (Cliente $cliente) use ($empresa): string {
            $proforma = Proforma::factory()->create(['cliente_id' => $cliente->id]);

            return view('proformas.pdf', [
                'proforma' => $proforma->load('items'),
                'cliente' => $cliente,
                'items' => $proforma->items,
                'empresa' => $empresa,
                'moneda' => 'BOB',
                'simbolo' => 'Bs',
            ])->render();
        };

        // Sin NIT: la línea empieza por el teléfono, sin "·" delante.
        $sinNit = $html(Cliente::factory()->create(['nit_ci' => null, 'telefono' => '70011223', 'email' => 'ana@cliente.test']));
        $this->assertMatchesRegularExpression('#<div class="muted">\s*Tel: 70011223 · ana@cliente\.test#', $sinNit);

        // Con todos los datos.
        $completo = $html(Cliente::factory()->create(['nit_ci' => '123', 'telefono' => '444', 'email' => 'x@cliente.test']));
        $this->assertStringContainsString('NIT/CI: 123 · Tel: 444 · x@cliente.test', $completo);
    }

    public function test_el_pie_de_una_aceptada_no_deja_espacio_antes_del_punto(): void
    {
        $empresa = Empresa::query()->findOrFail(1);
        $operario = User::factory()->create(['rol' => Rol::Operario, 'name' => 'Operario Demo']);
        $proforma = Proforma::factory()->aceptada()->create([
            'aceptada_at' => '2026-11-08 00:13:00',
            'aceptada_por' => $operario->id,
        ]);

        $html = view('proformas.pdf', [
            'proforma' => $proforma->load('items', 'aceptador'),
            'cliente' => $proforma->cliente,
            'items' => $proforma->items,
            'empresa' => $empresa,
            'moneda' => 'BOB',
            'simbolo' => 'Bs',
        ])->render();

        $this->assertStringContainsString('Aceptada el 08/11/2026 00:13 por Operario Demo.', $html);
    }
}
