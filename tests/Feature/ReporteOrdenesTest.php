<?php

namespace Tests\Feature;

use App\Enums\EstadoOrden;
use App\Enums\Rol;
use App\Models\Cliente;
use App\Models\OrdenServicio;
use App\Models\Proforma;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReporteOrdenesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $ana;

    private User $beto;

    private Cliente $clienteA;

    private Cliente $clienteB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-15 12:00:00', 'America/La_Paz'));

        $this->admin = User::factory()->create(['rol' => Rol::Admin]);
        $this->ana = User::factory()->create(['rol' => Rol::Operario, 'name' => 'Ana Operaria']);
        $this->beto = User::factory()->create(['rol' => Rol::Operario, 'name' => 'Beto Operario']);
        $this->clienteA = Cliente::factory()->create(['nombre_razon' => 'Constructora Andina', 'nit_ci' => '111']);
        $this->clienteB = Cliente::factory()->create(['nombre_razon' => 'Hotel Illimani', 'nit_ci' => '222']);

        $proformaA = Proforma::factory()->aceptada()->create(['cliente_id' => $this->clienteA->id]);
        $proformaB = Proforma::factory()->aceptada()->create(['cliente_id' => $this->clienteB->id]);

        $orden = fn (Proforma $proforma, string $numero, array $datos) => OrdenServicio::factory()
            ->paraProforma($proforma)
            ->create(['numero' => $numero, ...$datos]);

        $finalizada = fn (string $fin) => [
            'estado' => EstadoOrden::Finalizada,
            'fecha_inicio' => CarbonImmutable::parse($fin, 'America/La_Paz')->subHour(),
            'fecha_fin' => CarbonImmutable::parse($fin, 'America/La_Paz'),
        ];

        // Noviembre: 8 órdenes.
        $orden($proformaA, 'OS-2026-000001', ['operario_id' => $this->ana->id, 'fecha_programada' => '2026-11-10', ...$finalizada('2026-11-10 15:00')]);
        $orden($proformaA, 'OS-2026-000002', ['operario_id' => $this->ana->id, 'fecha_programada' => '2026-11-05', ...$finalizada('2026-11-08 09:00')]);
        // Terminó a las 22:00 del mismo día programado: a tiempo.
        $orden($proformaA, 'OS-2026-000003', ['operario_id' => $this->beto->id, 'fecha_programada' => '2026-11-12', ...$finalizada('2026-11-12 22:00')]);
        $orden($proformaB, 'OS-2026-000004', ['operario_id' => $this->beto->id, 'fecha_programada' => '2026-11-20', 'estado' => EstadoOrden::EnProceso, 'fecha_inicio' => now()]);
        $orden($proformaB, 'OS-2026-000005', ['operario_id' => $this->ana->id, 'fecha_programada' => '2026-11-25', 'estado' => EstadoOrden::Asignada]);
        $orden($proformaA, 'OS-2026-000006', ['fecha_programada' => '2026-11-30']);
        // Sin fecha programada: cuenta por el día de creación.
        $orden($proformaA, 'OS-2026-000007', ['operario_id' => $this->beto->id, 'fecha_programada' => null, 'created_at' => CarbonImmutable::parse('2026-11-03 10:00', 'America/La_Paz'), ...$finalizada('2026-11-04 10:00')]);
        $orden($proformaA, 'OS-2026-000010', ['fecha_programada' => '2026-11-14', 'estado' => EstadoOrden::Cancelada, 'motivo_cancelacion' => 'Prueba', 'cancelada_at' => now()]);

        // Fuera del periodo.
        $orden($proformaA, 'OS-2026-000008', ['operario_id' => $this->ana->id, 'fecha_programada' => '2026-10-31', ...$finalizada('2026-11-02 10:00')]);
        $orden($proformaA, 'OS-2026-000009', ['operario_id' => $this->ana->id, 'fecha_programada' => '2026-12-01', 'estado' => EstadoOrden::Asignada]);
        $orden($proformaA, 'OS-2026-000011', ['fecha_programada' => null, 'created_at' => CarbonImmutable::parse('2026-10-20 10:00', 'America/La_Paz')]);
    }

    public function test_por_defecto_resume_el_mes_actual_por_estado_operario_y_cumplimiento(): void
    {
        $this->actingAs($this->admin)->getJson('/api/v1/reportes/ordenes')
            ->assertOk()
            ->assertJsonPath('periodo', ['desde' => '2026-11-01', 'hasta' => '2026-11-30'])
            ->assertJsonPath('total', 8)
            ->assertJsonPath('por_estado', [
                'PENDIENTE' => 1,
                'ASIGNADA' => 1,
                'EN_PROCESO' => 1,
                'FINALIZADA' => 4,
                'CANCELADA' => 1,
            ])
            ->assertJsonPath('por_operario', [
                ['operario_id' => $this->ana->id, 'operario' => 'Ana Operaria', 'pendientes' => 0, 'asignadas' => 1, 'en_proceso' => 0, 'finalizadas' => 2, 'canceladas' => 0, 'total' => 3],
                ['operario_id' => $this->beto->id, 'operario' => 'Beto Operario', 'pendientes' => 0, 'asignadas' => 0, 'en_proceso' => 1, 'finalizadas' => 2, 'canceladas' => 0, 'total' => 3],
                // Sin operario: una pendiente y una cancelada; las columnas suman el total.
                ['operario_id' => null, 'operario' => null, 'pendientes' => 1, 'asignadas' => 0, 'en_proceso' => 0, 'finalizadas' => 0, 'canceladas' => 1, 'total' => 2],
            ])
            ->assertJsonPath('cumplimiento', [
                'a_tiempo' => 2,
                'con_atraso' => 1,
                'sin_fecha' => 1,
                'porcentaje_a_tiempo' => 66.7,
            ])
            ->assertJsonCount(1, 'atrasadas')
            ->assertJsonPath('atrasadas.0.numero', 'OS-2026-000002')
            ->assertJsonPath('atrasadas.0.fecha_programada', '2026-11-05')
            ->assertJsonPath('atrasadas.0.fecha_finalizacion', '2026-11-08')
            ->assertJsonPath('atrasadas.0.dias_atraso', 3);
    }

    public function test_filtra_por_cliente_y_por_operario(): void
    {
        $this->actingAs($this->admin)->getJson("/api/v1/reportes/ordenes?cliente_id={$this->clienteB->id}")
            ->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonPath('por_estado.ASIGNADA', 1)
            ->assertJsonPath('por_estado.EN_PROCESO', 1);

        $this->actingAs($this->admin)->getJson("/api/v1/reportes/ordenes?operario_id={$this->ana->id}")
            ->assertOk()
            ->assertJsonPath('total', 3)
            ->assertJsonCount(1, 'por_operario')
            ->assertJsonPath('cumplimiento.a_tiempo', 1)
            ->assertJsonPath('cumplimiento.con_atraso', 1)
            ->assertJsonPath('cumplimiento.porcentaje_a_tiempo', 50);
    }

    public function test_rango_de_fechas_explicito_y_rango_vacio(): void
    {
        $this->actingAs($this->admin)->getJson('/api/v1/reportes/ordenes?desde=2026-10-01&hasta=2026-10-31')
            ->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonPath('cumplimiento.a_tiempo', 0)
            ->assertJsonPath('cumplimiento.con_atraso', 1);

        $this->actingAs($this->admin)->getJson('/api/v1/reportes/ordenes?desde=2025-01-01&hasta=2025-01-31')
            ->assertOk()
            ->assertJsonPath('total', 0)
            ->assertJsonPath('por_estado.FINALIZADA', 0)
            ->assertJsonPath('por_operario', [])
            ->assertJsonPath('cumplimiento', [
                'a_tiempo' => 0,
                'con_atraso' => 0,
                'sin_fecha' => 0,
                'porcentaje_a_tiempo' => null,
            ])
            ->assertJsonPath('atrasadas', []);
    }

    public function test_valida_el_rango_y_los_filtros(): void
    {
        $this->actingAs($this->admin)->getJson('/api/v1/reportes/ordenes?desde=2026-11-10&hasta=2026-11-01')
            ->assertStatus(422)
            ->assertJsonValidationErrors('hasta');

        $this->actingAs($this->admin)->getJson('/api/v1/reportes/ordenes?desde=10/11/2026')
            ->assertStatus(422)
            ->assertJsonValidationErrors('desde');

        $this->actingAs($this->admin)->getJson('/api/v1/reportes/ordenes?cliente_id=999999')
            ->assertStatus(422)
            ->assertJsonValidationErrors('cliente_id');
    }

    public function test_exporta_el_detalle_por_orden_en_csv(): void
    {
        $response = $this->actingAs($this->admin)->get('/api/v1/reportes/ordenes?formato=csv');

        $response->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('reporte-ordenes_2026-11-01_2026-11-30.csv', $response->headers->get('Content-Disposition'));

        $lineas = preg_split('/\r?\n/', trim(ltrim($response->streamedContent(), "\xEF\xBB\xBF")));

        $this->assertSame('Código;Cliente;NIT/CI;Operario;Estado;"Fecha programada";"Fecha de finalización";Cumplimiento;"Días de atraso"', $lineas[0]);
        // 8 órdenes, una fila vacía y el resumen por operario (encabezado + 3 filas).
        $this->assertCount(14, $lineas);
        $this->assertContains('OS-2026-000002;"Constructora Andina";111;"Ana Operaria";Finalizada;2026-11-05;2026-11-08;"Con atraso";3', $lineas);
        $this->assertContains('OS-2026-000006;"Constructora Andina";111;"Sin asignar";Pendiente;2026-11-30;;;', $lineas);
        $this->assertSame('', $lineas[9]);
        $this->assertSame('Operario;Pendientes;Asignadas;"En proceso";Finalizadas;Canceladas;Total', $lineas[10]);
        $this->assertSame('"Sin asignar";1;0;0;0;1;2', $lineas[13]);
    }

    public function test_csv_sin_ordenes_no_agrega_el_resumen_por_operario(): void
    {
        $response = $this->actingAs($this->admin)->get('/api/v1/reportes/ordenes?formato=csv&desde=2025-01-01&hasta=2025-01-31');

        $lineas = preg_split('/\r?\n/', trim(ltrim($response->streamedContent(), "\xEF\xBB\xBF")));

        $this->assertCount(1, $lineas);
    }
}
