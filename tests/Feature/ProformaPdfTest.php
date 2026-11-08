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
