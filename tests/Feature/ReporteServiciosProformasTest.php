<?php

namespace Tests\Feature;

use App\Enums\EstadoOrden;
use App\Enums\EstadoProforma;
use App\Enums\Rol;
use App\Models\Cliente;
use App\Models\OrdenItem;
use App\Models\OrdenServicio;
use App\Models\Proforma;
use App\Models\Servicio;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReporteServiciosProformasTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Cliente $clienteA;

    private Cliente $clienteB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-15 12:00:00', 'America/La_Paz'));

        $this->admin = User::factory()->create(['rol' => Rol::Admin]);
        $this->clienteA = Cliente::factory()->create();
        $this->clienteB = Cliente::factory()->create();

        $dia = fn (string $fecha) => CarbonImmutable::parse("{$fecha} 10:00", 'America/La_Paz');
        $proforma = fn (Cliente $cliente, string $emision, EstadoProforma $estado, string $total, array $extra = []) => Proforma::factory()->create([
            'cliente_id' => $cliente->id,
            'fecha_emision' => $emision,
            'estado' => $estado,
            'subtotal' => $total,
            'total' => $total,
            ...$extra,
        ]);

        // Cotizadas en noviembre: 100.10 + 250.25 + 80.00 = 430.35 (el borrador no cuenta).
        $proforma($this->clienteA, '2026-11-03', EstadoProforma::Enviada, '100.10', ['enviada_at' => $dia('2026-11-03')]);
        $aceptadaA = $proforma($this->clienteA, '2026-11-04', EstadoProforma::Aceptada, '250.25', ['aceptada_at' => $dia('2026-11-06')]);
        $proforma($this->clienteB, '2026-11-05', EstadoProforma::Rechazada, '80.00', ['rechazada_at' => $dia('2026-11-07'), 'motivo_rechazo' => 'Caro']);
        $proforma($this->clienteA, '2026-11-08', EstadoProforma::Borrador, '999.00');
        // Emitida en octubre pero aceptada en noviembre: suma en aceptado, no en cotizado.
        $aceptadaB = $proforma($this->clienteB, '2026-10-28', EstadoProforma::Aceptada, '300.30', ['aceptada_at' => $dia('2026-11-02')]);
        $octubre = $proforma($this->clienteA, '2026-10-10', EstadoProforma::Aceptada, '500.00', ['aceptada_at' => $dia('2026-10-12')]);

        $mantenimiento = Servicio::factory()->create(['nombre' => 'Mantenimiento de aire', 'categoria' => 'Mantenimiento']);
        $instalacion = Servicio::factory()->create(['nombre' => 'Instalación', 'categoria' => 'Instalación']);

        $orden = fn (Proforma $proforma, ?string $programada, array $extra = []) => OrdenServicio::factory()
            ->paraProforma($proforma)
            ->create(['fecha_programada' => $programada, ...$extra]);
        $linea = fn (OrdenServicio $orden, Servicio $servicio, string $cantidad, string $total) => OrdenItem::factory()->servicio()->create([
            'orden_id' => $orden->id,
            'servicio_id' => $servicio->id,
            'cantidad' => $cantidad,
            'precio_unitario' => $total,
            'total_linea' => $total,
        ]);

        $ordenA = $orden($aceptadaA, '2026-11-10');
        $linea($ordenA, $mantenimiento, '2', '300.10');
        $linea($ordenA, $instalacion, '1', '150.05');
        OrdenItem::factory()->create(['orden_id' => $ordenA->id]); // material: no cuenta

        $ordenB = $orden($aceptadaB, '2026-11-20', ['estado' => EstadoOrden::Asignada]);
        $linea($ordenB, $mantenimiento, '1', '150.05');
        $linea($ordenB, $instalacion, '1', '199.99');

        $cancelada = $orden($aceptadaA, '2026-11-12', ['estado' => EstadoOrden::Cancelada, 'motivo_cancelacion' => 'Prueba', 'cancelada_at' => now()]);
        $linea($cancelada, $mantenimiento, '5', '999.00');

        $deOctubre = $orden($octubre, '2026-10-30');
        $linea($deOctubre, $instalacion, '4', '400.00');
    }

    public function test_por_defecto_resume_proformas_y_servicios_del_mes(): void
    {
        $this->actingAs($this->admin)->getJson('/api/v1/reportes/servicios')
            ->assertOk()
            ->assertJsonPath('periodo', ['desde' => '2026-11-01', 'hasta' => '2026-11-30'])
            ->assertJsonPath('proformas', [
                'cotizado' => 430.35,
                'cotizadas' => 3,
                'aceptado' => 550.55,
                'aceptadas' => 2,
                'rechazadas' => 1,
                'en_espera' => 1,
                'tasa_aceptacion' => 66.7,
            ])
            ->assertJsonCount(2, 'servicios')
            ->assertJsonPath('servicios.0.nombre', 'Mantenimiento de aire')
            ->assertJsonPath('servicios.0.categoria', 'Mantenimiento')
            ->assertJsonPath('servicios.0.cantidad', 3)
            ->assertJsonPath('servicios.0.monto', 450.15)
            ->assertJsonPath('servicios.1.nombre', 'Instalación')
            ->assertJsonPath('servicios.1.cantidad', 2)
            ->assertJsonPath('servicios.1.monto', 350.04);
    }

    public function test_filtra_por_cliente(): void
    {
        $this->actingAs($this->admin)->getJson("/api/v1/reportes/servicios?cliente_id={$this->clienteB->id}")
            ->assertOk()
            ->assertJsonPath('proformas.cotizado', 80)
            ->assertJsonPath('proformas.aceptado', 300.3)
            ->assertJsonPath('proformas.rechazadas', 1)
            ->assertJsonPath('proformas.en_espera', 0)
            ->assertJsonPath('proformas.tasa_aceptacion', 50)
            ->assertJsonPath('servicios.0.nombre', 'Instalación')
            ->assertJsonPath('servicios.0.monto', 199.99)
            ->assertJsonPath('servicios.1.monto', 150.05);

        $this->actingAs($this->admin)->getJson("/api/v1/reportes/servicios?cliente_id={$this->clienteA->id}")
            ->assertOk()
            ->assertJsonPath('servicios.0.cantidad', 2)
            ->assertJsonPath('servicios.0.monto', 300.1)
            ->assertJsonPath('servicios.1.monto', 150.05);
    }

    public function test_rango_vacio_devuelve_ceros_y_tasa_nula(): void
    {
        $this->actingAs($this->admin)->getJson('/api/v1/reportes/servicios?desde=2025-01-01&hasta=2025-01-31')
            ->assertOk()
            ->assertJsonPath('proformas', [
                'cotizado' => 0,
                'cotizadas' => 0,
                'aceptado' => 0,
                'aceptadas' => 0,
                'rechazadas' => 0,
                'en_espera' => 0,
                'tasa_aceptacion' => null,
            ])
            ->assertJsonPath('servicios', []);
    }

    public function test_exporta_el_ranking_de_servicios_en_csv(): void
    {
        $response = $this->actingAs($this->admin)->get('/api/v1/reportes/servicios?formato=csv');

        $response->assertOk();
        $this->assertStringContainsString('reporte-servicios_2026-11-01_2026-11-30.csv', $response->headers->get('Content-Disposition'));

        $lineas = preg_split('/\r?\n/', trim(ltrim($response->streamedContent(), "\xEF\xBB\xBF")));

        $this->assertSame([
            'Servicio;Categoría;Cantidad;"Monto (Bs)"',
            '"Mantenimiento de aire";Mantenimiento;3;450,15',
            'Instalación;Instalación;2;350,04',
        ], $lineas);
    }
}
