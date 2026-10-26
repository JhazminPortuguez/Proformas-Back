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
}
