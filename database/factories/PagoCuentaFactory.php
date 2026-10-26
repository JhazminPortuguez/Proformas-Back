<?php

namespace Database\Factories;

use App\Enums\MetodoPago;
use App\Models\CuentaPorCobrar;
use App\Models\PagoCuenta;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PagoCuenta>
 */
class PagoCuentaFactory extends Factory
{
    protected $model = PagoCuenta::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'cuenta_id' => CuentaPorCobrar::factory(),
            'monto' => 100,
            'metodo' => MetodoPago::Efectivo,
            'fecha_pago' => today()->toDateString(),
            'referencia' => null,
            'observaciones' => null,
            'created_by' => User::factory()->admin(),
            'anulado_at' => null,
            'anulado_por' => null,
            'motivo_anulacion' => null,
        ];
    }

    public function anulado(): static
    {
        return $this->state(fn () => [
            'anulado_at' => now(),
            'motivo_anulacion' => 'Anulado en pruebas.',
        ]);
    }
}
