<?php

namespace Database\Factories;

use App\Enums\TipoMovimientoInventario;
use App\Models\MovimientoInventario;
use App\Models\Producto;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MovimientoInventario>
 */
class MovimientoInventarioFactory extends Factory
{
    protected $model = MovimientoInventario::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $anterior = fake()->randomFloat(3, 0, 100);
        $cantidad = fake()->randomFloat(3, 1, 10);

        return [
            'producto_id' => Producto::factory(),
            'orden_id' => null,
            'orden_item_id' => null,
            'tipo' => TipoMovimientoInventario::Entrada,
            'cantidad' => $cantidad,
            'costo_unitario' => fake()->randomFloat(2, 1, 500),
            'stock_anterior' => $anterior,
            'stock_nuevo' => $anterior + $cantidad,
            'motivo' => 'Movimiento de prueba',
            'created_by' => null,
        ];
    }

    public function consumo(): static
    {
        return $this->state(fn (array $attributes): array => [
            'tipo' => TipoMovimientoInventario::Consumo,
            'cantidad' => -abs((float) $attributes['cantidad']),
            'stock_nuevo' => (float) $attributes['stock_anterior'] - abs((float) $attributes['cantidad']),
        ]);
    }
}
