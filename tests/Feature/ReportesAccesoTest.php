<?php

namespace Tests\Feature;

use App\Enums\Rol;
use App\Models\Cliente;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Los reportes son solo del administrador. */
class ReportesAccesoTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{string}>
     */
    public static function endpoints(): array
    {
        return [
            'ordenes' => ['/api/v1/reportes/ordenes'],
            'servicios' => ['/api/v1/reportes/servicios'],
            'inventario' => ['/api/v1/reportes/inventario'],
            'cobros' => ['/api/v1/reportes/cobros'],
            'clientes' => ['/api/v1/reportes/clientes'],
        ];
    }

    #[DataProvider('endpoints')]
    public function test_el_operario_recibe_403(string $endpoint): void
    {
        $operario = User::factory()->create(['rol' => Rol::Operario]);

        $this->actingAs($operario)->getJson($endpoint)->assertForbidden();
        $this->actingAs($operario)->get("{$endpoint}?formato=csv")->assertForbidden();
    }

    #[DataProvider('endpoints')]
    public function test_el_cliente_recibe_403(string $endpoint): void
    {
        $cliente = User::factory()->create(['rol' => Rol::Cliente]);

        $this->actingAs($cliente)->getJson($endpoint)->assertForbidden();
    }

    #[DataProvider('endpoints')]
    public function test_sin_sesion_recibe_401(string $endpoint): void
    {
        $this->getJson($endpoint)->assertUnauthorized();
    }

    #[DataProvider('endpoints')]
    public function test_el_admin_accede(string $endpoint): void
    {
        $admin = User::factory()->create(['rol' => Rol::Admin]);

        $this->actingAs($admin)->getJson($endpoint)->assertOk();
    }

    public function test_el_filtro_de_clientes_solo_envia_id_nombre_y_nit(): void
    {
        $admin = User::factory()->create(['rol' => Rol::Admin]);
        $hotel = Cliente::factory()->create(['nombre_razon' => 'Hotel Illimani', 'nit_ci' => '222', 'email' => 'hotel@example.com']);
        $andina = Cliente::factory()->create(['nombre_razon' => 'Constructora Andina', 'nit_ci' => null]);

        $this->actingAs($admin)->getJson('/api/v1/reportes/clientes')
            ->assertOk()
            ->assertExactJson(['data' => [
                ['id' => $andina->id, 'nombre_razon' => 'Constructora Andina', 'nit_ci' => null],
                ['id' => $hotel->id, 'nombre_razon' => 'Hotel Illimani', 'nit_ci' => '222'],
            ]]);
    }
}
