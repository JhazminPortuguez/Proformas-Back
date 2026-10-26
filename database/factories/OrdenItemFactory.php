<?php

namespace Database\Factories;

use App\Enums\TipoProformaItem;
use App\Models\OrdenItem;
use App\Models\OrdenServicio;
use App\Models\Producto;
use App\Models\Servicio;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrdenItem>
 */
class OrdenItemFactory extends Factory
{
    protected $model = OrdenItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $cantidad = fake()->numberBetween(1, 5);
        $precio = fake()->randomFloat(2, 10, 500);

        return [
            'orden_id' => OrdenServicio::factory(),
            'tipo' => TipoProformaItem::Material,
            'servicio_id' => null,
            'producto_id' => Producto::factory(),
            'descripcion' => fake()->words(4, true),
            'unidad_medida' => 'unidad',
            'cantidad' => $cantidad,
            'precio_unitario' => $precio,
            'total_linea' => round($cantidad * $precio, 2),
        ];
    }

    public function servicio(): static
    {
        return $this->state(fn () => [
            'tipo' => TipoProformaItem::Servicio,
            'servicio_id' => Servicio::factory(),
            'producto_id' => null,
            'unidad_medida' => 'servicio',
        ]);
    }

    public function libre(): static
    {
        return $this->state(fn () => [
            'tipo' => TipoProformaItem::Libre,
            'servicio_id' => null,
            'producto_id' => null,
            'descripcion' => fake()->sentence(4),
            'unidad_medida' => null,
        ]);
    }
}
