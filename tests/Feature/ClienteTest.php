<?php

namespace Tests\Feature;

use App\Enums\Rol;
use App\Models\Cliente;
use App\Models\Proforma;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClienteTest extends TestCase
{
    use RefreshDatabase;

    public function test_listado_incluye_conteo_de_proformas(): void
    {
        $admin = User::factory()->create(['rol' => Rol::Admin]);
        $cliente = Cliente::factory()->create();

        Proforma::factory()->create(['cliente_id' => $cliente->id]);

        $this->actingAs($admin)
            ->getJson('/api/v1/clientes')
            ->assertOk()
            ->assertJsonPath('data.0.proformas_count', 1);
    }

    public function test_busqueda_ignora_acentos_y_mayusculas(): void
    {
        $admin = User::factory()->create(['rol' => Rol::Admin]);
        Cliente::factory()->create(['nombre_razon' => 'Taller Ñandú']);

        $this->actingAs($admin)
            ->getJson('/api/v1/clientes?search=NANDU')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.nombre_razon', 'Taller Ñandú');
    }
}
