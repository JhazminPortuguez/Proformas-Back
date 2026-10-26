<?php

namespace Database\Factories;

use App\Enums\EstadoCobro;
use App\Models\CuentaPorCobrar;
use App\Models\OrdenServicio;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CuentaPorCobrar>
 */
class CuentaPorCobrarFactory extends Factory
{
    protected $model = CuentaPorCobrar::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'orden_id' => OrdenServicio::factory()->finalizada(),
            'cliente_id' => function (array $attributes): int {
                return (int) OrdenServicio::query()
                    ->withoutGlobalScope('empresa')
                    ->whereKey($attributes['orden_id'])
                    ->value('cliente_id');
            },
            'numero' => sprintf('CXC-%d-%06d', now()->year, fake()->unique()->numberBetween(100000, 899999)),
            'fecha_emision' => today()->toDateString(),
            'fecha_vencimiento' => today()->addDays(30)->toDateString(),
            'moneda' => 'BOB',
            'monto_total' => 1000,
            'saldo' => 1000,
            'estado_cobro' => EstadoCobro::Pendiente,
            'observaciones' => null,
            'created_by' => null,
            'updated_by' => null,
        ];
    }

    public function pagada(): static
    {
        return $this->state(fn () => [
            'saldo' => 0,
            'estado_cobro' => EstadoCobro::Pagada,
        ]);
    }

    public function vencida(): static
    {
        return $this->state(fn () => [
            'fecha_emision' => today()->subDays(60)->toDateString(),
            'fecha_vencimiento' => today()->subDays(30)->toDateString(),
        ]);
    }
}
