<?php

namespace Database\Factories;

use App\Enums\TipoProformaItem;
use App\Models\Producto;
use App\Models\Proforma;
use App\Models\ProformaItem;
use App\Models\Servicio;
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
            'tipo' => TipoProformaItem::Material,
            'producto_id' => Producto::factory(),
            'servicio_id' => null,
            'nombre' => fake()->words(3, true),
            'descripcion' => null,
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
            'producto_id' => null,
            'servicio_id' => Servicio::factory(),
            'unidad_medida' => 'servicio',
        ]);
    }

    public function libre(): static
    {
        return $this->state(fn () => [
            'tipo' => TipoProformaItem::Libre,
            'producto_id' => null,
            'servicio_id' => null,
            'descripcion' => fake()->sentence(4),
            'nombre' => fake()->words(3, true),
            'unidad_medida' => null,
        ]);
    }
}
