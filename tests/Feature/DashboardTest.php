<?php

namespace Tests\Feature;

use App\Enums\EstadoProforma;
use App\Enums\Rol;
use App\Models\Cliente;
use App\Models\Proforma;
use App\Models\Servicio;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_devuelve_metricas_para_admin(): void
    {
        $admin = User::factory()->create(['rol' => Rol::Admin]);

        Cliente::factory()->count(2)->create();
        Servicio::factory()->count(3)->create();

        Proforma::factory()->create([
            'fecha_emision' => now()->toDateString(),
            'estado' => EstadoProforma::Borrador,
            'total' => 100.50,
        ]);
        Proforma::factory()->create([
            'fecha_emision' => now()->toDateString(),
            'estado' => EstadoProforma::Enviada,
            'total' => 50.25,
        ]);

        $response = $this->actingAs($admin)->getJson('/api/v1/dashboard');

        $response->assertOk()
            ->assertJsonStructure([
                'clientes',
                'servicios',
                'proformas',
                'proformas_por_estado' => [
                    EstadoProforma::Borrador->value,
                    EstadoProforma::Enviada->value,
                    EstadoProforma::Aceptada->value,
                    EstadoProforma::Rechazada->value,
                ],
                'monto_proformas_mes',
                'actividad_reciente',
                'ordenes_pendientes',
                'saldo_por_cobrar',
            ])
            ->assertJsonPath('clientes', 4)
            ->assertJsonPath('servicios', 3)
            ->assertJsonPath('proformas', 2)
            ->assertJsonPath('proformas_por_estado.BORRADOR', 1)
            ->assertJsonPath('proformas_por_estado.ENVIADA', 1)
            ->assertJsonPath('proformas_por_estado.ACEPTADA', 0)
            ->assertJsonPath('proformas_por_estado.RECHAZADA', 0)
            ->assertJsonPath('monto_proformas_mes', 150.75)
            ->assertJsonPath('ordenes_pendientes', null)
            ->assertJsonPath('saldo_por_cobrar', null);

        $this->assertCount(5, $response->json('actividad_reciente'));
    }

    public function test_operario_accede_al_dashboard(): void
    {
        $operario = User::factory()->create(['rol' => Rol::Operario]);

        $this->actingAs($operario)->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonPath('clientes', 0)
            ->assertJsonPath('servicios', 0)
            ->assertJsonPath('proformas', 0)
            ->assertJsonPath('monto_proformas_mes', 0);
    }

    public function test_cliente_no_accede_al_dashboard(): void
    {
        $cliente = User::factory()->create(['rol' => Rol::Cliente]);

        $this->actingAs($cliente)->getJson('/api/v1/dashboard')->assertForbidden();
    }
}
