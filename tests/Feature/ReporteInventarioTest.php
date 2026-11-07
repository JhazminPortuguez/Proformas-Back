<?php

namespace Tests\Feature;

use App\Enums\Rol;
use App\Enums\TipoMovimientoInventario;
use App\Models\Cliente;
use App\Models\MovimientoInventario;
use App\Models\OrdenServicio;
use App\Models\Producto;
use App\Models\Proforma;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReporteInventarioTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $beto;

    private Cliente $clienteA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-15 12:00:00', 'America/La_Paz'));

        $this->admin = User::factory()->create(['rol' => Rol::Admin]);
        $ana = User::factory()->create(['rol' => Rol::Operario]);
        $this->beto = User::factory()->create(['rol' => Rol::Operario]);
        $this->clienteA = Cliente::factory()->create();
        $clienteB = Cliente::factory()->create();

        $ordenA = OrdenServicio::factory()
            ->paraProforma(Proforma::factory()->aceptada()->create(['cliente_id' => $this->clienteA->id]))
            ->create(['operario_id' => $ana->id]);
        $ordenB = OrdenServicio::factory()
            ->paraProforma(Proforma::factory()->aceptada()->create(['cliente_id' => $clienteB->id]))
            ->create(['operario_id' => $this->beto->id]);

        $cable = Producto::factory()->create(['nombre' => 'Cable', 'unidad_medida' => 'metro', 'stock_actual' => 50, 'stock_minimo' => 10]);
        $filtro = Producto::factory()->create(['nombre' => 'Filtro', 'unidad_medida' => 'unidad', 'stock_actual' => 2, 'stock_minimo' => 5]);
        $gas = Producto::factory()->create(['nombre' => 'Gas', 'unidad_medida' => 'kg', 'stock_actual' => 1.5, 'stock_minimo' => 1.5]);
        Producto::factory()->create(['nombre' => 'Tornillo', 'stock_actual' => 100, 'stock_minimo' => 0]);

        $consumo = fn (Producto $producto, ?OrdenServicio $orden, string $cantidad, string $costo, string $cuando, TipoMovimientoInventario $tipo = TipoMovimientoInventario::Consumo) => MovimientoInventario::factory()->create([
            'producto_id' => $producto->id,
            'orden_id' => $orden?->id,
            'tipo' => $tipo,
            'cantidad' => $cantidad,
            'costo_unitario' => $costo,
            'stock_anterior' => 0,
            'stock_nuevo' => 0,
            'created_at' => CarbonImmutable::parse($cuando, 'America/La_Paz'),
        ]);

        $consumo($cable, $ordenA, '-12.5', '3.40', '2026-11-05 10:00');   // 42.50
        $consumo($cable, $ordenB, '-2', '3.55', '2026-11-06 10:00');      //  7.10
        $consumo($filtro, $ordenA, '-1', '45.99', '2026-11-07 10:00');    // 45.99
        $consumo($gas, $ordenB, '-0.75', '120.00', '2026-11-08 10:00');   // 90.00
        $consumo($gas, $ordenA, '-0.333', '120.00', '2026-11-30 23:30');  // 39.96, último día del mes

        // No cuentan: consumo de octubre y una entrada de noviembre.
        $consumo($cable, $ordenA, '-3', '3.40', '2026-10-30 10:00');
        $consumo($cable, null, '100', '3.00', '2026-11-02 10:00', TipoMovimientoInventario::Entrada);
    }

    public function test_por_defecto_suma_el_consumo_del_mes_y_lista_el_stock_bajo(): void
    {
        $this->actingAs($this->admin)->getJson('/api/v1/reportes/inventario')
            ->assertOk()
            ->assertJsonPath('periodo', ['desde' => '2026-11-01', 'hasta' => '2026-11-30'])
            ->assertJsonPath('consumo', [
                'costo_total' => 225.55,
                'movimientos' => 5,
                'productos' => 3,
            ])
            ->assertJsonPath('productos.0.nombre', 'Gas')
            ->assertJsonPath('productos.0.unidad_medida', 'kg')
            ->assertJsonPath('productos.0.cantidad', 1.083)
            ->assertJsonPath('productos.0.costo', 129.96)
            ->assertJsonPath('productos.1.nombre', 'Cable')
            ->assertJsonPath('productos.1.cantidad', 14.5)
            ->assertJsonPath('productos.1.costo', 49.6)
            ->assertJsonPath('productos.2.nombre', 'Filtro')
            ->assertJsonPath('productos.2.costo', 45.99)
            ->assertJsonCount(2, 'stock_bajo')
            ->assertJsonPath('stock_bajo.0.nombre', 'Filtro')
            ->assertJsonPath('stock_bajo.0.stock_actual', 2)
            ->assertJsonPath('stock_bajo.0.stock_minimo', 5)
            ->assertJsonPath('stock_bajo.0.faltante', 3)
            ->assertJsonPath('stock_bajo.1.nombre', 'Gas')
            ->assertJsonPath('stock_bajo.1.faltante', 0);
    }

    public function test_filtra_el_consumo_por_cliente_y_por_operario(): void
    {
        $this->actingAs($this->admin)->getJson("/api/v1/reportes/inventario?cliente_id={$this->clienteA->id}")
            ->assertOk()
            ->assertJsonPath('consumo.costo_total', 128.45)
            ->assertJsonPath('consumo.movimientos', 3)
            ->assertJsonPath('productos.0.nombre', 'Filtro')
            ->assertJsonPath('productos.1.nombre', 'Cable')
            ->assertJsonPath('productos.1.costo', 42.5)
            ->assertJsonPath('productos.2.costo', 39.96)
            // El stock bajo no depende de los filtros.
            ->assertJsonCount(2, 'stock_bajo');

        $this->actingAs($this->admin)->getJson("/api/v1/reportes/inventario?operario_id={$this->beto->id}")
            ->assertOk()
            ->assertJsonPath('consumo.costo_total', 97.1)
            ->assertJsonPath('productos.0.nombre', 'Gas')
            ->assertJsonPath('productos.0.costo', 90)
            ->assertJsonPath('productos.1.costo', 7.1);
    }

    public function test_rango_vacio_no_tiene_consumo_pero_mantiene_el_stock_bajo(): void
    {
        $this->actingAs($this->admin)->getJson('/api/v1/reportes/inventario?desde=2025-01-01&hasta=2025-01-31')
            ->assertOk()
            ->assertJsonPath('consumo', ['costo_total' => 0, 'movimientos' => 0, 'productos' => 0])
            ->assertJsonPath('productos', [])
            ->assertJsonCount(2, 'stock_bajo');
    }

    public function test_exporta_el_consumo_por_producto_en_csv(): void
    {
        $response = $this->actingAs($this->admin)->get('/api/v1/reportes/inventario?formato=csv');

        $response->assertOk();
        $this->assertStringContainsString('reporte-inventario_2026-11-01_2026-11-30.csv', $response->headers->get('Content-Disposition'));

        $lineas = preg_split('/\r?\n/', trim(ltrim($response->streamedContent(), "\xEF\xBB\xBF")));

        $this->assertSame([
            'Producto;Unidad;"Cantidad consumida";"Costo (Bs)"',
            'Gas;kg;1,083;129,96',
            'Cable;metro;14,5;49,60',
            'Filtro;unidad;1;45,99',
        ], $lineas);
    }
}
