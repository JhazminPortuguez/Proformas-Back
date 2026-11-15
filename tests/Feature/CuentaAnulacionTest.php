<?php

namespace Tests\Feature;

use App\Actions\CuentasPorCobrar\AnularCuentaPorCobrar;
use App\Enums\EstadoCobro;
use App\Enums\Rol;
use App\Models\CuentaPorCobrar;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * X-11: el admin anula una cuenta por cobrar sin pagos vigentes, con motivo.
 * La anulada no suma en el dashboard ni en los reportes de cobranza, pero se
 * ve con el filtro de estado. No hay reembolsos.
 */
class CuentaAnulacionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-15 10:00:00', 'America/La_Paz'));
        $this->admin = User::factory()->create(['rol' => Rol::Admin]);
    }

    private function cuenta(float $monto = 300): CuentaPorCobrar
    {
        return CuentaPorCobrar::factory()->create([
            'monto_total' => $monto,
            'saldo' => $monto,
            'estado_cobro' => EstadoCobro::Pendiente,
            'fecha_emision' => '2026-11-01',
            'fecha_vencimiento' => '2026-12-01',
        ]);
    }

    public function test_admin_anula_una_cuenta_sin_pagos_con_motivo_y_queda_registrado(): void
    {
        $cuenta = $this->cuenta();

        $this->actingAs($this->admin)->getJson("/api/v1/cuentas-por-cobrar/{$cuenta->id}")
            ->assertJsonPath('acciones_disponibles', ['ver', 'pagar', 'anular']);

        $this->actingAs($this->admin)->postJson("/api/v1/cuentas-por-cobrar/{$cuenta->id}/anular", [
            'motivo' => 'Se emitió por error.',
        ])->assertOk()
            ->assertJsonPath('estado_cobro', 'ANULADA')
            ->assertJsonPath('estado_cobro_etiqueta', 'Anulada')
            ->assertJsonPath('anulada', true)
            ->assertJsonPath('saldo', 0)
            ->assertJsonPath('monto_total', 300)
            ->assertJsonPath('motivo_anulacion', 'Se emitió por error.')
            ->assertJsonPath('anulador.id', $this->admin->id)
            ->assertJsonPath('acciones_disponibles', ['ver']);

        $cuenta->refresh();
        $this->assertSame(EstadoCobro::Anulada, $cuenta->estado_cobro);
        $this->assertSame($this->admin->id, $cuenta->anulada_por);
        $this->assertNotNull($cuenta->anulada_at);

        // Ya anulada: no se vuelve a anular ni admite pagos.
        $this->actingAs($this->admin)->postJson("/api/v1/cuentas-por-cobrar/{$cuenta->id}/anular", ['motivo' => 'Otra vez.'])
            ->assertStatus(422)->assertJsonPath('errors.estado.0', 'La cuenta ya está anulada.');
        $this->actingAs($this->admin)->postJson("/api/v1/cuentas-por-cobrar/{$cuenta->id}/pagos", ['monto' => 10, 'metodo' => 'EFECTIVO'])
            ->assertStatus(422)->assertJsonPath('errors.monto.0', 'La cuenta está anulada: no admite pagos.');
    }

    public function test_motivo_obligatorio_y_solo_el_admin(): void
    {
        $cuenta = $this->cuenta();
        $operario = User::factory()->create(['rol' => Rol::Operario]);

        $this->actingAs($this->admin)->postJson("/api/v1/cuentas-por-cobrar/{$cuenta->id}/anular", ['motivo' => ''])
            ->assertStatus(422)->assertJsonValidationErrors('motivo');
        $this->actingAs($operario)->postJson("/api/v1/cuentas-por-cobrar/{$cuenta->id}/anular", ['motivo' => 'Error'])
            ->assertForbidden();
        $this->actingAs($operario)->getJson("/api/v1/cuentas-por-cobrar/{$cuenta->id}")
            ->assertJsonPath('acciones_disponibles', ['ver', 'pagar']);

        $this->assertSame(EstadoCobro::Pendiente, $cuenta->fresh()->estado_cobro);
    }

    public function test_con_pagos_vigentes_primero_hay_que_anular_los_pagos(): void
    {
        $cuenta = $this->cuenta();

        $pagoId = $this->actingAs($this->admin)->postJson("/api/v1/cuentas-por-cobrar/{$cuenta->id}/pagos", [
            'monto' => 100,
            'metodo' => 'EFECTIVO',
        ])->assertCreated()
            ->assertJsonPath('acciones_disponibles', ['ver', 'pagar'])
            ->json('pagos.0.id');

        $this->actingAs($this->admin)->postJson("/api/v1/cuentas-por-cobrar/{$cuenta->id}/anular", ['motivo' => 'Error'])
            ->assertStatus(422)
            ->assertJsonPath('errors.estado.0', AnularCuentaPorCobrar::MENSAJE_CON_PAGOS);

        $this->actingAs($this->admin)->postJson("/api/v1/pagos-cuenta/{$pagoId}/anular", ['motivo' => 'Pago duplicado.'])
            ->assertOk();

        $this->actingAs($this->admin)->postJson("/api/v1/cuentas-por-cobrar/{$cuenta->id}/anular", ['motivo' => 'Error'])
            ->assertOk()
            ->assertJsonPath('estado_cobro', 'ANULADA');
    }

    public function test_la_anulada_no_suma_en_dashboard_resumen_ni_reporte_pero_se_ve_con_el_filtro(): void
    {
        $vigente = $this->cuenta(200);
        $anulada = $this->cuenta(500);

        $this->actingAs($this->admin)->postJson("/api/v1/cuentas-por-cobrar/{$anulada->id}/anular", ['motivo' => 'Error'])
            ->assertOk();

        $this->actingAs($this->admin)->getJson('/api/v1/dashboard')
            ->assertJsonPath('cobros.cuentas', 1)
            ->assertJsonPath('cobros.saldo', 200)
            ->assertJsonPath('cobros.monto_total', 200);

        $this->actingAs($this->admin)->getJson('/api/v1/cuentas-por-cobrar/resumen')
            ->assertJsonPath('cuentas', 1)
            ->assertJsonPath('pendientes', 1)
            ->assertJsonPath('saldo', 200)
            ->assertJsonPath('monto_total', 200);

        $reporte = $this->actingAs($this->admin)->getJson('/api/v1/reportes/cobros')->assertOk();
        $this->assertSame(200, (int) $reporte->json('saldo_pendiente'));
        $this->assertSame([$vigente->numero], array_column($reporte->json('cuentas'), 'numero'));

        // El listado normal no la muestra; con el filtro de estado sí.
        $this->assertSame([$vigente->id], array_column(
            $this->actingAs($this->admin)->getJson('/api/v1/cuentas-por-cobrar')->json('data'),
            'id',
        ));
        $this->assertSame([$anulada->id], array_column(
            $this->actingAs($this->admin)->getJson('/api/v1/cuentas-por-cobrar?estado_cobro=ANULADA')->json('data'),
            'id',
        ));
    }
}
