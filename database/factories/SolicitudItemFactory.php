<?php

namespace Database\Factories;

use App\Models\SolicitudCotizacion;
use App\Models\SolicitudItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SolicitudItem>
 */
class SolicitudItemFactory extends Factory
{
    protected $model = SolicitudItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'solicitud_id' => SolicitudCotizacion::factory(),
            'descripcion' => fake()->sentence(4),
            'cantidad' => fake()->numberBetween(1, 10),
            'unidad_medida' => 'unidad',
            'observaciones' => null,
        ];
    }
}
