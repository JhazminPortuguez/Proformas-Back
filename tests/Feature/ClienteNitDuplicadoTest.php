<?php

namespace Tests\Feature;

use App\Enums\Rol;
use App\Models\Cliente;
use App\Models\Empresa;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** C-02: NIT/CI duplicado en la misma empresa. */
class ClienteNitDuplicadoTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_se_crea_otro_cliente_con_el_mismo_nit_y_el_mensaje_nombra_al_existente(): void
    {
        $admin = User::factory()->create(['rol' => Rol::Admin]);
        Cliente::factory()->create(['nombre_razon' => 'Constructora Andina', 'nit_ci' => '1020304']);

        foreach (['1020304', ' 1020 304 '] as $nit) {
            $this->actingAs($admin)->postJson('/api/v1/clientes', [
                'nombre_razon' => 'Otra empresa',
                'nit_ci' => $nit,
            ])->assertStatus(422)
                ->assertJsonPath('errors.nit_ci.0', 'El NIT/CI '.trim($nit).' ya está registrado para el cliente «Constructora Andina».');
        }

        $this->assertSame(1, Cliente::query()->count());
    }

    public function test_vacio_y_cero_no_cuentan_como_duplicado(): void
    {
        $admin = User::factory()->create(['rol' => Rol::Admin]);
        Cliente::factory()->create(['nit_ci' => '0']);
        Cliente::factory()->create(['nit_ci' => null]);

        foreach (['0', null, ''] as $nit) {
            $this->actingAs($admin)->postJson('/api/v1/clientes', [
                'nombre_razon' => 'Consumidor final',
                'nit_ci' => $nit,
            ])->assertCreated();
        }
    }

    public function test_al_editar_solo_se_valida_si_el_nit_cambia(): void
    {
        $admin = User::factory()->create(['rol' => Rol::Admin]);
        Cliente::factory()->create(['nombre_razon' => 'Hotel Illimani', 'nit_ci' => '555']);
        $cliente = Cliente::factory()->create(['nit_ci' => '777']);

        // Mismo NIT propio: se puede guardar.
        $this->actingAs($admin)->putJson("/api/v1/clientes/{$cliente->id}", [
            'nombre_razon' => 'Nuevo nombre',
            'nit_ci' => '777',
        ])->assertOk();

        // Cambiar al NIT de otro cliente: no.
        $this->actingAs($admin)->putJson("/api/v1/clientes/{$cliente->id}", ['nit_ci' => '555'])
            ->assertStatus(422)
            ->assertJsonPath('errors.nit_ci.0', 'El NIT/CI 555 ya está registrado para el cliente «Hotel Illimani».');

        // Dos clientes antiguos que ya compartían NIT se pueden seguir editando.
        $gemelo = Cliente::factory()->create(['nit_ci' => '555']);
        $this->actingAs($admin)->putJson("/api/v1/clientes/{$gemelo->id}", [
            'nit_ci' => '555',
            'telefono' => '70000000',
        ])->assertOk();
    }

    public function test_otra_empresa_puede_tener_el_mismo_nit(): void
    {
        $admin = User::factory()->create(['rol' => Rol::Admin]);
        $otra = Empresa::factory()->create();
        Cliente::query()->withoutGlobalScopes()->create([
            'empresa_id' => $otra->id,
            'nombre_razon' => 'Cliente de otra empresa',
            'nit_ci' => '999',
            'estado' => 'activo',
        ]);

        $this->actingAs($admin)->postJson('/api/v1/clientes', [
            'nombre_razon' => 'Cliente propio',
            'nit_ci' => '999',
        ])->assertCreated();
    }
}
