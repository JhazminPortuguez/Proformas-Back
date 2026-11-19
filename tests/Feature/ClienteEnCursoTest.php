<?php

namespace Tests\Feature;

use App\Enums\EstadoCliente;
use App\Enums\EstadoOrden;
use App\Enums\EstadoProforma;
use App\Enums\Rol;
use App\Models\Cliente;
use App\Models\OrdenServicio;
use App\Models\Proforma;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Punto 2: aviso antes de desactivar un cliente con trabajo en curso. */
class ClienteEnCursoTest extends TestCase
{
    use RefreshDatabase;

    public function test_lista_proformas_enviadas_y_ordenes_activas_y_desactivar_no_las_cambia(): void
    {
        $admin = User::factory()->create(['rol' => Rol::Admin]);
        $cliente = Cliente::factory()->create();

        Proforma::factory()->create(['cliente_id' => $cliente->id, 'numero' => 'PR-2026-000012', 'estado' => EstadoProforma::Enviada]);
        Proforma::factory()->create(['cliente_id' => $cliente->id, 'numero' => 'PR-2026-000003', 'estado' => EstadoProforma::Enviada]);
        Proforma::factory()->create(['cliente_id' => $cliente->id, 'numero' => 'PR-2026-000004', 'estado' => EstadoProforma::Borrador]);
        $aceptada = Proforma::factory()->aceptada()->create(['cliente_id' => $cliente->id]);
        OrdenServicio::factory()->paraProforma($aceptada)->create(['numero' => 'OS-2026-000009', 'estado' => EstadoOrden::EnProceso]);
        OrdenServicio::factory()->paraProforma($aceptada)->create(['numero' => 'OS-2026-000002', 'estado' => EstadoOrden::Finalizada]);

        $this->actingAs($admin)->getJson("/api/v1/clientes/{$cliente->id}/en-curso")
            ->assertOk()
            ->assertExactJson([
                'proformas' => ['PR-2026-000003', 'PR-2026-000012'],
                'ordenes' => ['OS-2026-000009'],
            ]);

        // Se permite desactivarlo; lo que está en curso sigue igual.
        $this->actingAs($admin)->putJson("/api/v1/clientes/{$cliente->id}", ['estado' => 'inactivo', 'version' => $cliente->fresh()->version])
            ->assertOk();
        $this->assertSame(EstadoCliente::Inactivo, $cliente->fresh()->estado);
        $this->assertSame(2, Proforma::query()->where('estado', 'ENVIADA')->count());
        $this->assertSame(EstadoOrden::EnProceso, OrdenServicio::query()->where('numero', 'OS-2026-000009')->value('estado'));
    }

    public function test_sin_trabajo_en_curso_devuelve_listas_vacias_y_el_cliente_portal_no_accede(): void
    {
        $admin = User::factory()->create(['rol' => Rol::Admin]);
        $cliente = Cliente::factory()->create();

        $this->actingAs($admin)->getJson("/api/v1/clientes/{$cliente->id}/en-curso")
            ->assertOk()
            ->assertExactJson(['proformas' => [], 'ordenes' => []]);

        $usuarioCliente = User::factory()->create(['rol' => Rol::Cliente, 'cliente_id' => $cliente->id]);
        $this->actingAs($usuarioCliente)->getJson("/api/v1/clientes/{$cliente->id}/en-curso")
            ->assertForbidden();
    }

    public function test_filtro_activos_excluye_inactivos(): void
    {
        $admin = User::factory()->create(['rol' => Rol::Admin]);
        Cliente::factory()->create(['nombre_razon' => 'Activo SA']);
        Cliente::factory()->prospecto()->create(['nombre_razon' => 'Prospecto SA']);
        Cliente::factory()->inactivo()->create(['nombre_razon' => 'Inactivo SA']);

        $nombres = collect($this->actingAs($admin)->getJson('/api/v1/clientes?activos=1&per_page=100')
            ->assertOk()->json('data'))->pluck('nombre_razon')->sort()->values()->all();

        $this->assertSame(['Activo SA', 'Prospecto SA'], $nombres);
    }
}
