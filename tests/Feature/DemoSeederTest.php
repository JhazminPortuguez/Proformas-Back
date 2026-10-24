<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Producto;
use App\Models\Proforma;
use App\Models\ProformaItem;
use App\Models\Servicio;
use App\Models\User;
use Database\Seeders\DemoSeeder;
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
            'numero' => 'PR-2026-900003',
            'estado' => 'ACEPTADA',
        ]);
    }
}
