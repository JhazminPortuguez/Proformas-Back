<?php

namespace Tests\Feature;

use App\Enums\Rol;
use App\Models\Cliente;
use App\Models\CuentaPorCobrar;
use App\Models\PagoCuenta;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReporteCobrosTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Cliente $clienteB;

    protected function setUp(): void
    {
        parent::setUp();

        // Hoy: 15/11/2026 en Bolivia.
        $this->travelTo(CarbonImmutable::parse('2026-11-15 12:00:00', 'America/La_Paz'));

        $this->admin = User::factory()->create(['rol' => Rol::Admin]);
        $clienteA = Cliente::factory()->create(['nombre_razon' => 'Constructora Andina', 'nit_ci' => '111']);
        $this->clienteB = Cliente::factory()->create(['nombre_razon' => 'Hotel Illimani', 'nit_ci' => '222']);

        $cuenta = fn (int $n, Cliente $cliente, string $saldo, ?string $vencimiento) => CuentaPorCobrar::factory()->create([
            'numero' => sprintf('CXC-2026-%06d', $n),
            'cliente_id' => $cliente->id,
            'monto_total' => '1000.00',
            'saldo' => $saldo,
            'fecha_emision' => CarbonImmutable::parse($vencimiento)->subDays(30)->toDateString(),
            'fecha_vencimiento' => $vencimiento,
        ]);

        $c1 = $cuenta(1, $clienteA, '100.10', '2026-11-20');        // al día
        $cuenta(2, $clienteA, '200.20', '2026-11-15');              // vence hoy: al día
        $c3 = $cuenta(3, $this->clienteB, '300.30', '2026-11-14');  // 1 día
        $cuenta(4, $clienteA, '400.40', '2026-10-16');              // 30 días
        $c5 = $cuenta(5, $this->clienteB, '500.50', '2026-10-15');  // 31 días
        $cuenta(6, $clienteA, '600.60', '2026-08-17');              // 90 días
        $cuenta(7, $this->clienteB, '700.70', '2026-08-16')         // 91 días
            ->update(['concepto' => 'CANCELACION']);                // cobro por cancelación
        $cuenta(8, $clienteA, '0.00', '2026-08-01');                // pagada: no cuenta

        $pago = fn (CuentaPorCobrar $cuenta, string $monto, string $fecha, bool $anulado = false) => PagoCuenta::factory()
            ->state($anulado ? ['anulado_at' => now(), 'motivo_anulacion' => 'Prueba'] : [])
            ->create(['cuenta_id' => $cuenta->id, 'monto' => $monto, 'fecha_pago' => $fecha]);

        // Cobrado en noviembre: 10.05 + 20.10 + 0.01 = 30.16.
        $pago($c1, '10.05', '2026-11-01');
        $pago($c3, '20.10', '2026-11-30');
        $pago($c5, '0.01', '2026-11-10');
        $pago($c1, '99.99', '2026-10-31');         // fuera del periodo
        $pago($c5, '33.33', '2026-11-10', true);   // anulado
    }

    public function test_por_defecto_cobrado_del_mes_y_saldos_por_antiguedad_a_hoy(): void
    {
        $response = $this->actingAs($this->admin)->getJson('/api/v1/reportes/cobros')
            ->assertOk()
            ->assertJsonPath('periodo', ['desde' => '2026-11-01', 'hasta' => '2026-11-30'])
            ->assertJsonPath('cobrado', 30.16)
            ->assertJsonPath('pagos', 3)
            ->assertJsonPath('saldo_pendiente', 2802.8)
            ->assertJsonPath('cuentas_con_saldo', 7)
            ->assertJsonPath('vencidas', 5)
            ->assertJsonPath('monto_vencido', 2502.5)
            ->assertJsonPath('aging', [
                'al_dia' => 300.3,
                'd1_30' => 700.7,
                'd31_60' => 500.5,
                'd61_90' => 600.6,
                'd90_plus' => 700.7,
            ])
            ->assertJsonPath('cuentas.0', [
                'id' => CuentaPorCobrar::query()->where('numero', 'CXC-2026-000007')->value('id'),
                'numero' => 'CXC-2026-000007',
                'concepto' => 'CANCELACION',
                'concepto_etiqueta' => 'Cancelación',
                'cliente' => ['nombre_razon' => 'Hotel Illimani', 'nit_ci' => '222'],
                'fecha_vencimiento' => '2026-08-16',
                'dias_vencida' => 91,
                'saldo' => 700.7,
                'franja' => 'd90_plus',
            ]);

        $this->assertSame(
            ['CXC-2026-000007', 'CXC-2026-000006', 'CXC-2026-000005', 'CXC-2026-000004', 'CXC-2026-000003', 'CXC-2026-000001', 'CXC-2026-000002'],
            array_column($response->json('cuentas'), 'numero'),
        );
        $this->assertSame([91, 90, 31, 30, 1, 0, 0], array_column($response->json('cuentas'), 'dias_vencida'));
    }

    public function test_el_dashboard_y_el_reporte_coinciden_en_saldos(): void
    {
        $dashboard = $this->actingAs($this->admin)->getJson('/api/v1/dashboard')->json('cobros');
        $reporte = $this->actingAs($this->admin)->getJson('/api/v1/reportes/cobros')->json();

        $this->assertSame($dashboard['aging'], $reporte['aging']);
        $this->assertSame($dashboard['saldo'], $reporte['saldo_pendiente']);
        $this->assertSame($dashboard['vencidas'], $reporte['vencidas']);
        $this->assertSame($dashboard['monto_vencido'], $reporte['monto_vencido']);
    }

    public function test_filtra_por_cliente(): void
    {
        $this->actingAs($this->admin)->getJson("/api/v1/reportes/cobros?cliente_id={$this->clienteB->id}")
            ->assertOk()
            ->assertJsonPath('cobrado', 20.11)
            ->assertJsonPath('pagos', 2)
            ->assertJsonPath('saldo_pendiente', 1501.5)
            ->assertJsonPath('vencidas', 3)
            ->assertJsonPath('aging', [
                'al_dia' => 0,
                'd1_30' => 300.3,
                'd31_60' => 500.5,
                'd61_90' => 0,
                'd90_plus' => 700.7,
            ])
            ->assertJsonCount(3, 'cuentas');
    }

    public function test_rango_vacio_no_tiene_cobros_pero_mantiene_los_saldos_de_hoy(): void
    {
        $this->actingAs($this->admin)->getJson('/api/v1/reportes/cobros?desde=2025-01-01&hasta=2025-01-31')
            ->assertOk()
            ->assertJsonPath('periodo', ['desde' => '2025-01-01', 'hasta' => '2025-01-31'])
            ->assertJsonPath('cobrado', 0)
            ->assertJsonPath('pagos', 0)
            ->assertJsonPath('saldo_pendiente', 2802.8)
            ->assertJsonCount(7, 'cuentas');
    }

    public function test_exporta_las_cuentas_con_saldo_en_csv(): void
    {
        $response = $this->actingAs($this->admin)->get('/api/v1/reportes/cobros?formato=csv');

        $response->assertOk();
        $this->assertStringContainsString('reporte-cobros_2026-11-01_2026-11-30.csv', $response->headers->get('Content-Disposition'));

        $lineas = preg_split('/\r?\n/', trim(ltrim($response->streamedContent(), "\xEF\xBB\xBF")));

        $this->assertSame('Número;Concepto;Cliente;NIT/CI;Vencimiento;"Días vencida";"Saldo (Bs)";Antigüedad', $lineas[0]);
        $this->assertCount(8, $lineas);
        $this->assertSame('CXC-2026-000007;Cancelación;"Hotel Illimani";222;2026-08-16;91;700,70;"Más de 90 días"', $lineas[1]);
        $this->assertSame('CXC-2026-000002;Servicio;"Constructora Andina";111;2026-11-15;0;200,20;"Al día"', $lineas[7]);
    }
}
