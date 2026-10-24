<?php

namespace Database\Factories;

use App\Models\Producto;
use App\Models\Proforma;
use App\Models\ProformaItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProformaItem>
 */
class ProformaItemFactory extends Factory
{
    protected $model = ProformaItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $cantidad = fake()->numberBetween(1, 5);
        $precio = fake()->randomFloat(2, 10, 500);

        return [
            'proforma_id' => Proforma::factory(),
            'producto_id' => Producto::factory(),
            'descripcion' => null,
            'cantidad' => $cantidad,
            'precio_unitario' => $precio,
            'total_linea' => round($cantidad * $precio, 2),
        ];
    }
}
