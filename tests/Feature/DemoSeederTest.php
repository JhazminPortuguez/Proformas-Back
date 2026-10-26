<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\CuentaPorCobrar;
use App\Models\MovimientoInventario;
use App\Models\OrdenEvento;
use App\Models\OrdenItem;
use App\Models\OrdenServicio;
use App\Models\PagoCuenta;
use App\Models\Producto;
use App\Models\Proforma;
use App\Models\ProformaItem;
use App\Models\Servicio;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_seeder_es_idempotente_y_completo(): void
    {
        $this->seed(DemoSeeder::class);
        $this->seed(DemoSeeder::class);
        $this->assertSame(3, User::count());
        $this->assertSame(3, Cliente::count());
        $this->assertSame(6, Producto::count());
        $this->assertSame(4, Servicio::count());
        $this->assertSame(3, Proforma::count());
        $this->assertSame(7, ProformaItem::count());
        $this->assertSame(3, OrdenServicio::count());
        $this->assertSame(6, OrdenItem::count());
        $this->assertSame(9, OrdenEvento::count());
        $this->assertSame(7, MovimientoInventario::count());
        $this->assertSame(1, CuentaPorCobrar::count());
        $this->assertSame(1, PagoCuenta::count());

        $this->assertDatabaseHas('users', [
            'email' => 'cliente.demo@example.com',
            'rol' => 'cliente',
            'activo' => true,
        ]);

        $this->assertDatabaseHas('clientes', [
            'email' => 'cliente.demo@example.com',
            'estado' => 'activo',
        ]);

        $this->assertDatabaseHas('proformas', [
            'numero' => sprintf('PR-%d-%06d', now()->year, 3),
            'estado' => 'ACEPTADA',
        ]);

        $this->assertDatabaseHas('proforma_sequences', [
            'empresa_id' => 1,
            'anio' => now()->year,
            'ultimo_numero' => 3,
        ]);

        $this->assertDatabaseHas('ordenes_servicio', [
            'numero' => sprintf('OS-%d-%06d', now()->year, 1),
            'estado' => 'CANCELADA',
            'motivo_cancelacion' => 'El cliente reprogramó el servicio.',
        ]);

        $this->assertDatabaseHas('ordenes_servicio', [
            'numero' => sprintf('OS-%d-%06d', now()->year, 2),
            'estado' => 'EN_PROCESO',
        ]);

        $ordenCerrada = OrdenServicio::query()
            ->where('numero', sprintf('OS-%d-%06d', now()->year, 3))
            ->firstOrFail();

        $this->assertSame('FINALIZADA', $ordenCerrada->estado->value);
        $this->assertNotNull($ordenCerrada->cerrada_at);

        $cuenta = CuentaPorCobrar::query()->firstOrFail();

        $this->assertSame(sprintf('CXC-%d-%06d', now()->year, 1), $cuenta->numero);
        $this->assertSame('530.00', $cuenta->monto_total);
        $this->assertSame('265.00', $cuenta->saldo);
        $this->assertSame('PARCIAL', $cuenta->estado_cobro->value);
        $this->assertSame(1, $cuenta->pagos()->count());
        $this->assertSame('TRANSFERENCIA', $cuenta->pagos()->firstOrFail()->metodo->value);
        $this->assertSame('265.00', $cuenta->pagos()->firstOrFail()->monto);

        $ventilador = Producto::query()->where('nombre', 'Ventilador de reemplazo')->firstOrFail();
        $this->assertSame('7.000', $ventilador->stock_actual);

        $this->assertDatabaseHas('movimientos_inventario', [
            'producto_id' => $ventilador->id,
            'tipo' => 'CONSUMO',
            'cantidad' => -1,
            'stock_anterior' => 8,
            'stock_nuevo' => 7,
        ]);

        $this->assertSame(6, MovimientoInventario::query()
            ->where('tipo', 'ENTRADA')
            ->where('motivo', 'Stock inicial demo')
            ->count());

        $ordenAsignada = OrdenServicio::query()
            ->where('numero', sprintf('OS-%d-%06d', now()->year, 2))
            ->firstOrFail();

        $material = $ordenAsignada->items()->where('tipo', 'MATERIAL')->firstOrFail();
        $this->assertSame('1.000', $material->cantidad_consumida);
        $this->assertNull($material->consumido_at);

        $this->assertDatabaseHas('orden_sequences', [
            'empresa_id' => 1,
            'anio' => now()->year,
            'ultimo_numero' => 3,
        ]);

        $this->assertDatabaseHas('cuenta_sequences', [
            'empresa_id' => 1,
            'anio' => now()->year,
            'ultimo_numero' => 1,
        ]);
    }

    public function test_demo_seeder_funciona_con_eventos_de_modelo_desactivados(): void
    {
        Model::withoutEvents(fn () => $this->seed(DemoSeeder::class));

        $this->assertSame(1, PagoCuenta::count());
        $this->assertDatabaseHas('pagos_cuenta', [
            'empresa_id' => 1,
            'monto' => 265,
        ]);
    }
}
